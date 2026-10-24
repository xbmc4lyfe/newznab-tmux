<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CollectionsCleaningService;
use App\Services\RegexService;
use App\Services\Releases\Fingerprint\FingerprintProviderBreaker;
use App\Services\Releases\Verification\PublicationForwarderDiagnostics;
use App\Services\Releases\Verification\VerificationRunDiagnostics;
use App\Support\Passkeys\RelyingPartyIdResolver;
use App\Support\RustPipelineLibrary;
use App\Support\SizeUnit;
use App\Support\SqlError;
use Closure;
use FFI;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use PDOException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/**
 * Wave 14 Slice 6 parity and paired randomized benchmark suite for CollectionsCleaningService,
 * FingerprintProviderBreaker, VerificationRunDiagnostics, PublicationForwarderDiagnostics,
 * SizeUnit, SqlError, and RelyingPartyIdResolver.
 *
 * Paired randomized benchmark evidence (10,000 paired observations across 2 repetitions per
 * workload regime, `COLLECTIONS_SUPPORT_BENCHMARK=1`):
 *
 * | Method | Common Mean (PHP / Rust) | Common p99 (PHP / Rust) | Uncommon Mean (PHP / Rust) | Uncommon p99 (PHP / Rust) |
 * | --- | --- | --- | --- | --- |
 * | `CollectionsCleaningService::isMusicGroup` | 0.482x (50.0 / 103.7 ns) | 0.503x (84 / 167 ns) | 0.494x (55.9 / 113.2 ns) | 0.503x (84 / 167 ns) |
 * | `CollectionsCleaningService::musicSubject` | 0.270x (63.3 / 234.3 ns) | 0.251x (84 / 334 ns) | 0.691x (331.5 / 479.8 ns) | 0.733x (458 / 625 ns) |
 * | `CollectionsCleaningService::enhanceShortSubject` | 3.021x (1319.9 / 436.9 ns) | 3.076x (1667 / 542 ns) | 7.358x (6215.3 / 844.7 ns) | 7.718x (8042 / 1042 ns) |
 * | `CollectionsCleaningService::cleanGenericSubject` (warm) | 3.124x (1899.1 / 607.9 ns) | 3.292x (2334 / 709 ns) | 3.612x (5948.0 / 1646.8 ns) | 3.673x (7500 / 2042 ns) |
 * | `CollectionsCleaningService::cleanMusicSubject` (warm) | 1.944x (1234.3 / 634.8 ns) | 1.894x (1500 / 792 ns) | 1.891x (3144.1 / 1662.9 ns) | 1.918x (3917 / 2042 ns) |
 * | `FingerprintProviderBreaker::key` | 0.415x (205.5 / 495.4 ns) | 0.428x (250 / 584 ns) | 0.405x (267.1 / 659.4 ns) | 0.450x (375 / 833 ns) |
 * | `FingerprintProviderBreaker::suspectSeconds` | 0.367x (35.4 / 96.5 ns) | 0.336x (42 / 125 ns) | 0.410x (38.7 / 94.5 ns) | 0.664x (83 / 125 ns) |
 * | `VerificationRunDiagnostics::stop` | 0.428x (86.6 / 202.3 ns) | 0.430x (125 / 291 ns) | 0.464x (94.6 / 203.9 ns) | 0.500x (125 / 250 ns) |
 * | `VerificationRunDiagnostics::report` | 0.810x (761.1 / 939.2 ns) | 0.815x (917 / 1125 ns) | 0.867x (955.8 / 1102.9 ns) | 0.854x (1209 / 1416 ns) |
 * | `PublicationForwarderDiagnostics::stop` | 0.427x (62.6 / 146.5 ns) | 0.598x (125 / 209 ns) | 0.485x (66.2 / 136.5 ns) | 0.601x (125 / 208 ns) |
 * | `PublicationForwarderDiagnostics::outcome` | 0.501x (80.1 / 159.7 ns) | 0.500x (125 / 250 ns) | 0.639x (110.7 / 173.1 ns) | 0.546x (250 / 458 ns) |
 * | `PublicationForwarderDiagnostics::report` | 0.730x (520.9 / 713.9 ns) | 0.761x (666 / 875 ns) | 0.737x (515.5 / 699.9 ns) | 0.749x (625 / 834 ns) |
 * | `SizeUnit::toBytes` | 0.283x (74.3 / 262.4 ns) | 0.252x (84 / 333 ns) | 0.327x (89.3 / 273.1 ns) | 0.374x (125 / 334 ns) |
 * | `SizeUnit::fromBytes` | 0.169x (58.7 / 347.7 ns) | 0.183x (84 / 458 ns) | 0.220x (66.3 / 301.0 ns) | 0.224x (84 / 375 ns) |
 * | `SqlError::isTransientLock` | 0.256x (60.4 / 236.1 ns) | 0.288x (84 / 292 ns) | 0.766x (509.0 / 664.3 ns) | 0.749x (625 / 834 ns) |
 * | `SqlError::describe` | 0.417x (181.2 / 434.5 ns) | 0.461x (250 / 542 ns) | 0.838x (1376.2 / 1643.2 ns) | 0.820x (1709 / 2083 ns) |
 * | `RelyingPartyIdResolver::firstHost` | 0.551x (108.1 / 196.0 ns) | 0.574x (167 / 291 ns) | 0.699x (186.9 / 267.6 ns) | 0.749x (250 / 334 ns) |
 * | `RelyingPartyIdResolver::isValidRelyingPartyId` | 0.437x (59.4 / 136.0 ns) | 0.601x (125 / 208 ns) | 0.415x (57.3 / 138.2 ns) | 0.404x (84 / 208 ns) |
 * | `RelyingPartyIdResolver::resolve` | 0.889x (2076.9 / 2335.1 ns) | 0.877x (2667 / 3041 ns) | 0.949x (2272.3 / 2394.6 ns) | 0.946x (2917 / 3083 ns) |
 *
 * Production defaults: `$useRustFfi = false` across all 7 classes (`CollectionsCleaningService`
 * cold-cache `mb_convert_encoding` over `mb_list_encodings()`, `isMusicGroup`, and `musicSubject`
 * remain below 1.5x; the 6 support/diagnostics classes execute in 35–2,272 ns in native PHP so
 * FFI marshaling overhead keeps them below 1.5x).
 */
final class RustCollectionsCleaningAndSupportParityTest extends TestCase
{
    private const array TARGET_CLASSES = [
        CollectionsCleaningService::class,
        FingerprintProviderBreaker::class,
        VerificationRunDiagnostics::class,
        PublicationForwarderDiagnostics::class,
        SizeUnit::class,
        SqlError::class,
        RelyingPartyIdResolver::class,
    ];

    private const string FULL_CDEF = <<<'C'
        int32_t nntmux_collections_is_music_group(const char *group, size_t group_len);
        ssize_t nntmux_collections_extract_music_subject(const char *input, size_t input_len, char *out, size_t out_cap);
        ssize_t nntmux_collections_clean_generic_pre_normalize(const char *input, size_t len, char *out, size_t cap);
        ssize_t nntmux_collections_music_subject(const char *input, size_t len, const char *group, size_t group_len, char *out, size_t cap);
        ssize_t nntmux_collections_enhance_short_subject(const char *input, size_t len, const char *clean, size_t clean_len, char *out, size_t cap);
        ssize_t nntmux_fingerprint_breaker_key(const char *name, size_t name_len, const char *port, size_t port_len, uint8_t tls, const char *state, size_t state_len, const char *generation, size_t generation_len, char *out, size_t out_cap);
        int64_t nntmux_fingerprint_breaker_suspect_seconds(int64_t window_seconds, int64_t cooldown_seconds);
        int32_t nntmux_verification_diagnostics_stop(const char *reason, size_t reason_len, int64_t stopped, int64_t claims_admitted, int64_t limit, uint8_t has_loop_started, int64_t loop_started, int64_t max_seconds);
        ssize_t nntmux_verification_diagnostics_round_metrics(const char *input, size_t input_len, int64_t started, int64_t finished, uint8_t has_loop_started, int64_t loop_started, uint8_t has_stopped, int64_t stopped, char *out, size_t out_cap);
        int32_t nntmux_publication_forwarder_classify(const char *label, size_t label_len, uint32_t mode);
        ssize_t nntmux_publication_forwarder_metrics(int64_t batches, int64_t items, double linger_ms, double round_trip_ms, double slowest_round_trip_ms, int64_t started, int64_t finished, char *out, size_t out_cap);
        ssize_t nntmux_size_unit_convert(const char *value_str, size_t value_len, const char *unit_str, size_t unit_len, uint32_t mode, char *out, size_t out_cap);
        ssize_t nntmux_sql_error_describe(uint8_t is_query_exception, const char *sqlstate, size_t sqlstate_len, int64_t driver_code, const char *message, size_t message_len, uint8_t *is_transient_out, char *out_buf, size_t out_cap);
        ssize_t nntmux_passkey_resolve_rp_host(const char *input, size_t input_len, uint32_t mode, char *out_buf, size_t out_cap);
        C;

    private FFI $ffi;

    private Randomizer $random;

    protected function setUp(): void
    {
        parent::setUp();
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FULL_CDEF);
        self::assertNotNull($ffi, 'Rust pipeline FFI library must be built with Wave 14 Slice 6 exports.');
        $this->ffi = $ffi;
        $this->random = new Randomizer(new Mt19937(20261024));
    }

    protected function tearDown(): void
    {
        foreach (self::TARGET_CLASSES as $class) {
            $default = (new ReflectionClass($class))->getDefaultProperties()['useRustFfi'] ?? false;
            $class::setUseRustFfi((bool) $default);
        }
        parent::tearDown();
    }

    public function test_default_use_rust_ffi_flags_match_benchmark_threshold(): void
    {
        foreach (self::TARGET_CLASSES as $class) {
            self::assertFalse($class::usesRustFfi(), "{$class} should default to useRustFfi = false");
            $class::setUseRustFfi(true);
            self::assertTrue($class::usesRustFfi(), "{$class} should report usesRustFfi() = true when enabled");
            $class::setUseRustFfi(false);
            self::assertFalse($class::usesRustFfi(), "{$class} should report usesRustFfi() = false when disabled");
        }
    }

    public function test_c_abi_null_pointer_and_short_buffer_contracts(): void
    {
        $tiny = $this->ffi->new('char[2]');
        $flag = $this->ffi->new('uint8_t');

        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_collections_is_music_group(null, 1));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $this->ffi->nntmux_collections_is_music_group(null, 0));

        $musicSubj = 'Broderick_Smith-Unknown "00-broderick_smith.sfv" yEnc';
        // @phpstan-ignore method.notFound
        self::assertLessThan(0, $this->ffi->nntmux_collections_extract_music_subject($musicSubj, strlen($musicSubj), $tiny, 2));

        // @phpstan-ignore method.notFound
        self::assertLessThan(-3, $this->ffi->nntmux_fingerprint_breaker_key('news.example.com', 16, '563', 3, 1, 'open', 4, 'gen1', 4, $tiny, 2));

        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_verification_diagnostics_stop(null, 1, 0, 0, 0, 0, 0, 0));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_verification_diagnostics_round_metrics('short', 5, 0, 0, 0, 0, 0, 0, $tiny, 2));

        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_publication_forwarder_classify(null, 1, 0));
        // @phpstan-ignore method.notFound
        self::assertSame(-51, $this->ffi->nntmux_publication_forwarder_metrics(1, 5, 1.0, 2.0, 2.0, 0, 1000, $tiny, 2));

        // @phpstan-ignore method.notFound
        self::assertSame(-2, $this->ffi->nntmux_size_unit_convert('1', 1, 'PB', 2, 0, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-2, $this->ffi->nntmux_size_unit_convert('1', 1, 'PB', 2, 2, $tiny, 2));

        // @phpstan-ignore method.notFound
        self::assertSame(0, $this->ffi->nntmux_sql_error_describe(1, '40001', 5, 1213, 'Deadlock found', 14, FFI::addr($flag), null, 0));
        // @phpstan-ignore property.notFound
        self::assertSame(1, $flag->cdata);

        $buf = $this->ffi->new('char[256]');
        // Mode 2 of nntmux_sql_error_describe formats sqlstate=<state> driver_code=<code>: <collapsed>
        // @phpstan-ignore method.notFound
        $written = $this->ffi->nntmux_sql_error_describe(
            2,
            '40001',
            5,
            1213,
            "  Deadlock\n\t  found   (Connection: mariadb, SQL: INSERT) ",
            57,
            FFI::addr($flag),
            $buf,
            256
        );
        self::assertGreaterThan(0, $written);
        self::assertSame('sqlstate=40001 driver_code=1213: Deadlock found', FFI::string($buf, $written));

        // Mode 2 of nntmux_fingerprint_breaker_key formats 'releases:fingerprint:provider:'.sha1($provider).':'.$kind
        // @phpstan-ignore method.notFound
        $kLen = $this->ffi->nntmux_fingerprint_breaker_key('provider-1', 10, '', 0, 2, 'open', 4, '', 0, $buf, 256);
        self::assertGreaterThan(0, $kLen);
        self::assertSame('releases:fingerprint:provider:'.sha1('provider-1').':open', FFI::string($buf, $kLen));

        // Mode 3 of nntmux_passkey_resolve_rp_host validates strict multi-label RFC 1123 hostnames
        // @phpstan-ignore method.notFound
        $rpLen = $this->ffi->nntmux_passkey_resolve_rp_host(' Auth.Example-Site.COM:8443 , other.com ', 40, 3, $buf, 256);
        self::assertSame('auth.example-site.com', FFI::string($buf, $rpLen));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $this->ffi->nntmux_passkey_resolve_rp_host('localhost:8080', 14, 3, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $this->ffi->nntmux_passkey_resolve_rp_host('127.0.0.1:8080', 14, 3, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $this->ffi->nntmux_passkey_resolve_rp_host('-bad.example.com', 16, 3, $buf, 256));
    }

    public function test_collections_cleaning_service_parity(): void
    {
        $cleaner = $this->makeCollectionsCleaner();
        $groups = [
            '',
            'alt.binaries.test',
            'alt.binaries.music',
            'alt.binaries.flac',
            'alt.binaries.lossless',
            'alt.binaries.mp3',
            'alt.binaries.sounds',
            'alt.binaries.MUSIC',
            'alt.musicology',
            "alt.\xff.music",
        ];

        $subjects = [
            '',
            'Plain.Title-GRP',
            '[01/25] - "Movie.Title.2026-GRP.part01.rar" yEnc (1/50)',
            '[1/50] Some.Show.S01E02.1080p.WEB-DL-GROUP "release.part001.rar" yEnc (1/50)',
            'Broderick_Smith-Unknown_Country-2009-404 "00-broderick_smith-unknown_country-2009.sfv" yEnc',
            "Broderick_Smith-Unknown_Country-2009-404 \"00-broderick_smith.sfv\" yEnc\n",
            'Short_Name-GRP "00-short.sfv" yEnc',
            '[1/50] Artist_Album_2026_GRP "release.part001.rar" yEnc (1/50)',
            'Movie 365.15 KB yEnc',
            'Title1of2.Name.rar',
            'AutoRarPar123 Movie.vol01+03.par2',
            '"ABC.def" yEnc (1/2)',
            'a-b_c.d',
            " \tfoo\tbar\f\f baz\0 ",
            "\xff\xc3\x28 \"café.rar\" yEnc",
            'S/3/S*Z%7}',
        ];

        for ($i = 0; $i < 200; $i++) {
            $subjects[] = sprintf(
                '[%d/40] Show.Title.S02E%02d.1080p-GRP "archive.part%02d.rar" yEnc (1/30)',
                ($i % 40) + 1,
                ($i % 24) + 1,
                ($i % 40) + 1
            );
        }

        foreach ($groups as $group) {
            $cleaner->groupName = $group;
            self::assertSame($cleaner->isMusicGroupPhp(), $cleaner->isMusicGroupNative(), "isMusicGroup group={$group}");
        }

        foreach ($subjects as $idx => $subject) {
            foreach ($groups as $group) {
                $cleaner->subject = $subject;
                $cleaner->groupName = $group;

                self::assertSame(
                    $cleaner->musicSubjectPhp(),
                    $cleaner->musicSubjectNative(),
                    "musicSubject #{$idx}"
                );
                self::assertSame(
                    $cleaner->enhanceShortSubjectPhp('short'),
                    $cleaner->enhanceShortSubjectNative('short'),
                    "enhanceShortSubject #{$idx}"
                );
                self::assertSame(
                    $cleaner->cleanGenericSubjectPhp(),
                    $cleaner->cleanGenericSubjectNative(),
                    "cleanGenericSubject #{$idx}"
                );
                self::assertSame(
                    $cleaner->cleanMusicSubjectPhp(),
                    $cleaner->cleanMusicSubjectNative(),
                    "cleanMusicSubject #{$idx}"
                );

                CollectionsCleaningService::setUseRustFfi(false);
                $phpResult = $cleaner->collectionsCleaner($subject, $group);
                CollectionsCleaningService::setUseRustFfi(true);
                $rustResult = $cleaner->collectionsCleaner($subject, $group);
                self::assertSame($phpResult, $rustResult, "collectionsCleaner #{$idx} group={$group}");
            }
        }
    }

    public function test_fingerprint_provider_breaker_parity(): void
    {
        $servers = [
            ['name' => 'news.alternate.invalid', 'port' => '', 'tls' => false],
            ['name' => 'News.Provider.Example.COM', 'port' => '563', 'tls' => true],
            ['name' => 'backup.usenet.local', 'port' => '119', 'tls' => false],
            ['name' => 'UPPERCASE.HOST.NET', 'port' => '443', 'tls' => true],
        ];
        $states = ['generation', 'open', 'probe', 'recovered'];
        $generations = ['', 'a1b2c3d4e5f6', bin2hex(random_bytes(16))];

        foreach ($servers as $server) {
            foreach ($states as $state) {
                foreach ($generations as $gen) {
                    $expected = FingerprintProviderBreaker::keyPhp($server, $state, $gen);
                    $actual = FingerprintProviderBreaker::keyNative($server, $state, $gen);
                    self::assertSame($expected, $actual);
                }
            }
        }

        foreach ([-10, 0, 10, 30, 60, 300, 900, 1200, 3600] as $window) {
            foreach ([0, 15, 45, 120, 3600] as $cooldown) {
                self::assertSame(
                    FingerprintProviderBreaker::suspectSecondsPhp($window, $cooldown),
                    FingerprintProviderBreaker::suspectSecondsNative($window, $cooldown),
                    "suspectSeconds window={$window} cooldown={$cooldown}"
                );
            }
        }
    }

    public function test_verification_run_diagnostics_parity(): void
    {
        $reasons = [
            'limit',
            'deadline',
            'no_claim',
            'draining',
            'worker_unavailable',
            'initialization_unavailable',
            'initialization_error',
            'unsafe_scratch_root',
            'error',
            'unrecognized_reason',
            '',
        ];

        foreach ($reasons as $idx => $reason) {
            $loopStarted = $idx % 3 === 0 ? null : 5_000_000_000;
            $stopped = 6_500_000_000 + ($idx * 123_456_789);
            $finished = $stopped + 456_789_123;
            $limit = ($idx % 4) + 1;
            $maxSeconds = $idx % 2 === 0 ? 1 : 0;

            $phpDiag = new VerificationRunDiagnostics(1_000_000_000, '2026-10-24T10:00:00Z', $limit, $maxSeconds, 1);
            $rustDiag = new VerificationRunDiagnostics(1_000_000_000, '2026-10-24T10:00:00Z', $limit, $maxSeconds, 1);

            foreach ([$phpDiag, $rustDiag] as $d) {
                if ($idx % 2 === 0) {
                    $d->workerAcquired();
                }
                if ($loopStarted !== null) {
                    $d->loopStarted($loopStarted);
                }
                for ($c = 0; $c <= ($idx % 5); $c++) {
                    $d->claimCalled();
                    if ($c % 2 === 0) {
                        $d->claimed();
                    }
                }
                $d->verified('complete');
                $d->verified('unknown_verdict');
                $d->published();
                $d->handedOff();
                $d->outboxWaited(1_000_000_000, 1_000_000_000 + ($idx + 1) * 1_005_500);
                $d->returned($idx % 2);
            }

            // Populate identical phase timings via reflection so reportPhp and reportNative see the exact same float inputs
            $phasesProp = new ReflectionProperty(VerificationRunDiagnostics::class, 'phases');
            $phaseData = [
                'claim' => ['ms' => 1.005 + $idx * 0.1234, 'count' => 2],
                'prefetch' => ['ms' => 12.3455, 'count' => 1],
                'bookkeeping' => ['ms' => 0.3335, 'count' => 3],
                'manifest_read' => ['ms' => 2.675, 'count' => 1],
                'verify_passed' => ['ms' => 45.6789, 'count' => 4],
                'publish' => ['ms' => 0.0005, 'count' => 1],
            ];
            $phasesProp->setValue($phpDiag, $phaseData);
            $phasesProp->setValue($rustDiag, $phaseData);

            $phpDiag->stopPhp($reason, $stopped);
            self::assertTrue($rustDiag->stopNative($reason, $stopped));

            // Subsequent stop calls are ignored on both
            $phpDiag->stopPhp('limit', $stopped + 999_999_999);
            $rustDiag->stopNative('limit', $stopped + 999_999_999);

            $phpReport = $phpDiag->reportPhp($finished);
            $rustReport = $rustDiag->reportNative($finished);
            self::assertSame($phpReport, $rustReport, "VerificationRunDiagnostics mismatch at #{$idx} ({$reason})");
        }
    }

    public function test_publication_forwarder_diagnostics_parity(): void
    {
        $stopReasons = ['deadline', 'passes', 'terminated', 'locks_lost', 'worker_unavailable', 'disabled', 'slot_unavailable', 'error', 'custom_unknown'];
        $outcomes = ['published', 'filtered', 'metadata_review', 'deferred', 'stale', 'invalid', 'error', 'paused', 'retry', 'transport', 'no_release', 'unexpected_outcome'];

        foreach ($stopReasons as $idx => $reason) {
            $phpDiag = new PublicationForwarderDiagnostics(1_000_000_000, '2026-10-24T10:00:00Z', 2, 60);
            $rustDiag = new PublicationForwarderDiagnostics(1_000_000_000, '2026-10-24T10:00:00Z', 2, 60);

            $phpDiag->stopPhp($reason);
            self::assertTrue($rustDiag->stopNative($reason));

            // Second stop call preserves first stopReason
            $phpDiag->stopPhp('deadline');
            $rustDiag->stopNative('deadline');

            foreach ($outcomes as $oIdx => $status) {
                for ($k = 0; $k <= ($oIdx % 3); $k++) {
                    $phpDiag->outcomePhp($status);
                    self::assertTrue($rustDiag->outcomeNative($status));
                }
            }

            foreach ([$phpDiag, $rustDiag] as $d) {
                $d->adopted(3);
                if ($idx % 2 === 0) {
                    $d->batch(7, 2_000_000_000, 2_012_345_600, false);
                    $d->batch(3, 3_000_000_000, 3_004_567_800, true);
                }
                $d->lingerStarted();
                $d->lingerEnded('fill', 1.0055);
                $d->returned(0);
            }

            $finished = 5_123_456_700 + $idx * 100_000;
            self::assertSame(
                $phpDiag->reportPhp($finished),
                $rustDiag->reportNative($finished),
                "PublicationForwarderDiagnostics mismatch at #{$idx} ({$reason})"
            );
        }
    }

    public function test_size_unit_parity(): void
    {
        $values = [
            null,
            '',
            0,
            '0',
            -5,
            '-1.5',
            'not-a-number',
            1,
            '1',
            5,
            '5',
            100,
            1.5,
            '1.5',
            0.5,
            '0.5',
            2.345,
            '2.345',
            ' 12.75 ',
        ];

        foreach ($values as $idx => $value) {
            foreach (['MB', 'mb', 'GB', 'gb'] as $unit) {
                self::assertSame(
                    SizeUnit::toBytesPhp($value, $unit),
                    SizeUnit::toBytesNative($value, $unit),
                    "SizeUnit::toBytes mismatch at #{$idx} unit={$unit}"
                );
            }
        }

        // Extended binary power-of-1024 units in mode 2
        self::assertSame(512, SizeUnit::toBytesNative(512, 'B', true));
        self::assertSame(1024, SizeUnit::toBytesNative(1, 'K', true));
        self::assertSame(2048, SizeUnit::toBytesNative(2, 'KB', true));
        self::assertSame(1048576, SizeUnit::toBytesNative(1, 'M', true));
        self::assertSame(1073741824, SizeUnit::toBytesNative(1, 'G', true));
        self::assertSame(1099511627776, SizeUnit::toBytesNative(1, 'T', true));
        self::assertSame(1099511627776, SizeUnit::toBytesNative(1, 'TB', true));

        // Strict SizeUnit::toBytes rejects TB
        foreach ([false, true] as $useRust) {
            SizeUnit::setUseRustFfi($useRust);
            try {
                SizeUnit::toBytes(1, 'TB');
                self::fail('Expected InvalidArgumentException for unit TB.');
            } catch (InvalidArgumentException $e) {
                self::assertSame('Unsupported size unit [TB].', $e->getMessage());
            }
        }

        $byteInputs = [
            null,
            '',
            'not-numeric',
            -1024,
            -1,
            0,
            '0',
            1,
            512,
            1048576,
            524288000,
            1073741824,
            107374182400,
            1572864,
            1500000,
            '1500000',
            123456789,
        ];
        for ($i = 0; $i < 200; $i++) {
            $byteInputs[] = $this->random->getInt(-1000, 10_000_000_000);
        }

        foreach ($byteInputs as $idx => $bytes) {
            self::assertSame(
                SizeUnit::fromBytesPhp($bytes),
                SizeUnit::fromBytesNative($bytes),
                "SizeUnit::fromBytes mismatch at #{$idx}"
            );
        }
    }

    public function test_sql_error_parity(): void
    {
        $exceptions = [
            $this->makeQueryException('HY000', 1020, "SQLSTATE[HY000]: General error: 1020 Record has changed since last read in table 'collections'"),
            $this->makeQueryException('40001', 1213, 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'),
            $this->makeQueryException('40001', 1205, 'SQLSTATE[40001]: Serialization failure: 1205 Lock wait timeout exceeded; try restarting transaction'),
            $this->makeQueryException('23000', 1062, 'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry'),
            new RuntimeException('Deadlock found when trying to get lock'),
            new RuntimeException('Lock wait timeout exceeded'),
            new RuntimeException("Record has changed since last read in table 'collections'"),
            new RuntimeException('database is locked'),
            new RuntimeException('unrelated failure'),
            new RuntimeException(str_repeat('x', 1000)),
            new RuntimeException(str_repeat('é', 500)),
            new RuntimeException(str_repeat('é', 501).' (Connection: mariadb, SQL: SELECT 1)'),
            new RuntimeException(str_repeat('🎵', 505)),
        ];

        foreach ($exceptions as $idx => $exception) {
            self::assertSame(
                SqlError::isTransientLockPhp($exception),
                SqlError::isTransientLockNative($exception),
                "SqlError::isTransientLock mismatch at #{$idx}"
            );
            self::assertSame(
                SqlError::describePhp($exception),
                SqlError::describeNative($exception),
                "SqlError::describe mismatch at #{$idx}"
            );
        }
    }

    public function test_relying_party_id_resolver_parity(): void
    {
        $hostInputs = [
            '',
            '   ',
            ',',
            'auth.example.com',
            'auth.example.com:8443',
            'auth.example.com:8443, proxy.example.com:8080',
            '  sub.domain.example.org:443 , second.example.org ',
            'localhost:3000',
            '127.0.0.1:8000',
            '::1',
            '[::1]:8080',
            'example.com:notaport',
            'example.com:80:8080',
        ];

        foreach ($hostInputs as $idx => $input) {
            self::assertSame(
                RelyingPartyIdResolver::firstHostPhp($input),
                RelyingPartyIdResolver::firstHostNative($input),
                "firstHost mismatch at #{$idx}"
            );
        }

        $rpCandidates = [
            '',
            '   ',
            'localhost',
            ' localhost ',
            'LOCALHOST',
            'example.com',
            'auth.example.com',
            'a.b',
            '127.0.0.1',
            '0.0.0.0',
            '255.255.255.255',
            '256.1.1.1',
            '01.1.1.1',
            '::1',
            '2001:db8::1',
            '::ffff:192.0.2.128',
            'singlelabel',
        ];

        foreach ($rpCandidates as $idx => $cand) {
            self::assertSame(
                RelyingPartyIdResolver::isValidRelyingPartyIdPhp($cand),
                RelyingPartyIdResolver::isValidRelyingPartyIdNative($cand),
                "isValidRelyingPartyId mismatch at #{$idx} ({$cand})"
            );
        }

        $requestCases = [
            ['url' => 'http://127.0.0.1/passkeys', 'headers' => ['HTTP_X_FORWARDED_HOST' => 'auth.example.com:8443, proxy.local'], 'config' => 'example.com'],
            ['url' => 'http://127.0.0.1/passkeys', 'headers' => ['HTTP_X_ORIGINAL_HOST' => 'orig.example.com:443'], 'config' => 'example.com'],
            ['url' => 'http://localhost/passkeys', 'headers' => [], 'config' => 'example.com'],
            ['url' => 'http://127.0.0.1/passkeys', 'headers' => [], 'config' => '127.0.0.1'],
            ['url' => 'http://localhost/passkeys', 'headers' => [], 'config' => 'localhost'],
            ['url' => 'https://portal.example.net/passkeys', 'headers' => ['HTTP_X_FORWARDED_HOST' => '127.0.0.1:8080'], 'config' => ''],
        ];

        foreach ($requestCases as $idx => $case) {
            config()->set('passkeys.relying_party.id', $case['config']);
            $request = Request::create($case['url'], 'GET', [], [], [], $case['headers']);
            self::assertSame(
                RelyingPartyIdResolver::resolvePhp($request),
                RelyingPartyIdResolver::resolveNative($request),
                "RelyingPartyIdResolver::resolve mismatch at #{$idx}"
            );
        }
    }

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('COLLECTIONS_SUPPORT_BENCHMARK') !== '1') {
            self::markTestSkipped('Set COLLECTIONS_SUPPORT_BENCHMARK=1 to run paired randomized benchmarks.');
        }

        $repetitions = max(2, (int) (getenv('COLLECTIONS_SUPPORT_BENCHMARK_REPS') ?: 2));
        $pairsPerRep = max(4000, (int) (getenv('COLLECTIONS_SUPPORT_BENCHMARK_PAIRS') ?: 5000));
        $workloads = $this->buildBenchmarkWorkloads();
        $summary = [];

        foreach ($workloads as $methodName => $regimes) {
            foreach (['common', 'uncommon'] as $regime) {
                [$phpCall, $rustCall] = $regimes[$regime];
                self::assertSame($phpCall(), $rustCall(), "Benchmark pre-check failed for {$methodName} ({$regime})");

                for ($w = 0; $w < 250; $w++) {
                    $phpCall();
                    $rustCall();
                }

                $phpAll = [];
                $rustAll = [];
                $repStats = [];

                for ($rep = 0; $rep < $repetitions; $rep++) {
                    $phpRep = [];
                    $rustRep = [];
                    for ($i = 0; $i < $pairsPerRep; $i++) {
                        if ($this->random->getInt(0, 1) === 1) {
                            $t0 = hrtime(true);
                            $rustCall();
                            $rustNs = hrtime(true) - $t0;

                            $t1 = hrtime(true);
                            $phpCall();
                            $phpNs = hrtime(true) - $t1;
                        } else {
                            $t0 = hrtime(true);
                            $phpCall();
                            $phpNs = hrtime(true) - $t0;

                            $t1 = hrtime(true);
                            $rustCall();
                            $rustNs = hrtime(true) - $t1;
                        }
                        $phpRep[] = $phpNs;
                        $rustRep[] = $rustNs;
                        $phpAll[] = $phpNs;
                        $rustAll[] = $rustNs;
                    }
                    sort($phpRep);
                    sort($rustRep);
                    $rank = (int) ceil($pairsPerRep * 0.99) - 1;
                    $phpMean = array_sum($phpRep) / $pairsPerRep;
                    $rustMean = array_sum($rustRep) / $pairsPerRep;
                    $repStats[] = [
                        'rep' => $rep + 1,
                        'mean_speedup' => round($phpMean / max(1.0, $rustMean), 3),
                        'p99_speedup' => round($phpRep[$rank] / max(1, $rustRep[$rank]), 3),
                    ];
                }

                sort($phpAll);
                sort($rustAll);
                $totalPairs = count($phpAll);
                $p99Index = (int) ceil($totalPairs * 0.99) - 1;
                $phpMean = array_sum($phpAll) / $totalPairs;
                $rustMean = array_sum($rustAll) / $totalPairs;
                $phpP99 = $phpAll[$p99Index];
                $rustP99 = $rustAll[$p99Index];

                $summary[$methodName][$regime] = [
                    'samples' => $totalPairs,
                    'repetitions' => $repetitions,
                    'php_mean_ns' => round($phpMean, 1),
                    'rust_mean_ns' => round($rustMean, 1),
                    'php_p99_ns' => $phpP99,
                    'rust_p99_ns' => $rustP99,
                    'mean_speedup' => round($phpMean / max(1.0, $rustMean), 3),
                    'p99_speedup' => round($phpP99 / max(1, $rustP99), 3),
                    'reps' => $repStats,
                ];
            }
        }

        fwrite(STDERR, "\nWave 14 Slice 6 Benchmark Summary:\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * @return array<string, array{common: array{Closure, Closure}, uncommon: array{Closure, Closure}}>
     */
    private function buildBenchmarkWorkloads(): array
    {
        $cleanerCommon = $this->makeCollectionsCleaner();
        $cleanerCommon->subject = '[1/50] Some.Show.S01E02.1080p.WEB-DL-GROUP "release.part001.rar" yEnc (1/50)';
        $cleanerCommon->groupName = 'alt.binaries.flac';

        $cleanerUncommon = $this->makeCollectionsCleaner();
        $cleanerUncommon->subject = '[12/120] '.str_repeat('Long.Release_Name-', 16).'Some.Show.S01E02.1080p.WEB-DL-GROUP "archive.part012.rar" yEnc (12/120)';
        $cleanerUncommon->groupName = 'alt.binaries.lossless.flac';

        $cleanerMusicSpecialCommon = $this->makeCollectionsCleaner();
        $cleanerMusicSpecialCommon->subject = 'Broderick_Smith-Unknown_Country-2009-404 "00-broderick_smith-unknown_country-2009.sfv" yEnc';
        $cleanerMusicSpecialUncommon = $this->makeCollectionsCleaner();
        $cleanerMusicSpecialUncommon->subject = str_repeat('Artist_Name_', 20).'-AlbumTitle2026 "01-'.str_repeat('track_name_', 20).'.flac" yEnc';

        $serverCommon = ['name' => 'news.alternate.invalid', 'port' => '563', 'tls' => true];
        $serverUncommon = ['name' => str_repeat('sub.', 20).'news.provider.example.com', 'port' => '563', 'tls' => true];
        $genUncommon = str_repeat('abcdef0123456789', 4);

        $verifDiagCommon = new VerificationRunDiagnostics(1_000_000_000, '2026-10-24T10:00:00Z', 10, 300, 1);
        $verifDiagCommon->loopStarted(2_000_000_000);
        $verifDiagUncommon = new VerificationRunDiagnostics(1_000_000_000, '2026-10-24T10:00:00Z', 1000, 3600, 2);
        $verifDiagUncommon->loopStarted(2_000_000_000);
        $phasesProp = new ReflectionProperty(VerificationRunDiagnostics::class, 'phases');
        $phasesProp->setValue($verifDiagUncommon, [
            'claim' => ['ms' => 12.3456, 'count' => 50],
            'prefetch' => ['ms' => 234.5678, 'count' => 50],
            'bookkeeping' => ['ms' => 45.6789, 'count' => 50],
            'manifest_read' => ['ms' => 89.0123, 'count' => 50],
            'verify_passed' => ['ms' => 1234.5678, 'count' => 48],
            'verify_other' => ['ms' => 56.7891, 'count' => 2],
            'publish' => ['ms' => 345.6789, 'count' => 48],
            'finish' => ['ms' => 78.9012, 'count' => 50],
            'status_write' => ['ms' => 19.2837, 'count' => 50],
        ]);
        $stopReasonProp = new ReflectionProperty(VerificationRunDiagnostics::class, 'stopReason');

        $pubDiagCommon = new PublicationForwarderDiagnostics(1_000_000_000, '2026-10-24T10:00:00Z', 1, 60);
        $pubDiagCommon->batch(8, 2_000_000_000, 2_015_432_100, false);
        $pubDiagUncommon = new PublicationForwarderDiagnostics(1_000_000_000, '2026-10-24T10:00:00Z', 4, 600);
        $pubDiagUncommon->batch(64, 2_000_000_000, 2_125_678_900, false);
        $pubStopProp = new ReflectionProperty(PublicationForwarderDiagnostics::class, 'stopReason');
        $pubOutcomesProp = new ReflectionProperty(PublicationForwarderDiagnostics::class, 'outcomes');

        $queryExCommon = $this->makeQueryException(
            '40001',
            1213,
            'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'
        );
        $queryExUncommon = $this->makeQueryException(
            '23000',
            1062,
            'SQLSTATE[23000]: Integrity constraint violation: '.str_repeat('Duplicate key payload ', 30)
        );

        $reqCommon = Request::create('http://127.0.0.1/passkeys', 'GET', [], [], [], [
            'HTTP_X_FORWARDED_HOST' => 'auth.example.com:8443',
        ]);
        $reqUncommon = Request::create('http://127.0.0.1/passkeys', 'GET', [], [], [], [
            'HTTP_X_FORWARDED_HOST' => '127.0.0.1:8443, proxy.local',
            'HTTP_X_ORIGINAL_HOST' => str_repeat('sub.', 15).'auth.example.com:8443, fallback.example.com',
        ]);

        return [
            'CollectionsCleaningService::isMusicGroup' => [
                'common' => [
                    static fn (): bool => $cleanerCommon->isMusicGroupPhp(),
                    static fn (): ?bool => $cleanerCommon->isMusicGroupNative(),
                ],
                'uncommon' => [
                    static fn (): bool => $cleanerUncommon->isMusicGroupPhp(),
                    static fn (): ?bool => $cleanerUncommon->isMusicGroupNative(),
                ],
            ],
            'CollectionsCleaningService::musicSubject' => [
                'common' => [
                    static fn (): bool|string => $cleanerMusicSpecialCommon->musicSubjectPhp(),
                    static fn (): bool|string|null => $cleanerMusicSpecialCommon->musicSubjectNative(),
                ],
                'uncommon' => [
                    static fn (): bool|string => $cleanerMusicSpecialUncommon->musicSubjectPhp(),
                    static fn (): bool|string|null => $cleanerMusicSpecialUncommon->musicSubjectNative(),
                ],
            ],
            'CollectionsCleaningService::enhanceShortSubject' => [
                'common' => [
                    static fn (): string => $cleanerCommon->enhanceShortSubjectPhp('yEnc'),
                    static fn (): ?string => $cleanerCommon->enhanceShortSubjectNative('yEnc'),
                ],
                'uncommon' => [
                    static fn (): string => $cleanerUncommon->enhanceShortSubjectPhp('short'),
                    static fn (): ?string => $cleanerUncommon->enhanceShortSubjectNative('short'),
                ],
            ],
            'CollectionsCleaningService::cleanGenericSubject' => [
                'common' => [
                    static fn (): array => $cleanerCommon->cleanGenericSubjectPhp(),
                    static fn (): ?array => $cleanerCommon->cleanGenericSubjectNative(),
                ],
                'uncommon' => [
                    static fn (): array => $cleanerUncommon->cleanGenericSubjectPhp(),
                    static fn (): ?array => $cleanerUncommon->cleanGenericSubjectNative(),
                ],
            ],
            'CollectionsCleaningService::cleanMusicSubject' => [
                'common' => [
                    static fn (): array => $cleanerCommon->cleanMusicSubjectPhp(),
                    static fn (): ?array => $cleanerCommon->cleanMusicSubjectNative(),
                ],
                'uncommon' => [
                    static fn (): array => $cleanerUncommon->cleanMusicSubjectPhp(),
                    static fn (): ?array => $cleanerUncommon->cleanMusicSubjectNative(),
                ],
            ],
            'FingerprintProviderBreaker::key' => [
                'common' => [
                    static fn (): string => FingerprintProviderBreaker::keyPhp($serverCommon, 'open', 'gen123'),
                    static fn (): ?string => FingerprintProviderBreaker::keyNative($serverCommon, 'open', 'gen123'),
                ],
                'uncommon' => [
                    static fn (): string => FingerprintProviderBreaker::keyPhp($serverUncommon, 'recovered', $genUncommon),
                    static fn (): ?string => FingerprintProviderBreaker::keyNative($serverUncommon, 'recovered', $genUncommon),
                ],
            ],
            'FingerprintProviderBreaker::suspectSeconds' => [
                'common' => [
                    static fn (): int => FingerprintProviderBreaker::suspectSecondsPhp(900, 0),
                    static fn (): ?int => FingerprintProviderBreaker::suspectSecondsNative(900, 0),
                ],
                'uncommon' => [
                    static fn (): int => FingerprintProviderBreaker::suspectSecondsPhp(15, 120),
                    static fn (): ?int => FingerprintProviderBreaker::suspectSecondsNative(15, 120),
                ],
            ],
            'VerificationRunDiagnostics::stop' => [
                'common' => [
                    static function () use ($verifDiagCommon, $stopReasonProp): void {
                        $stopReasonProp->setValue($verifDiagCommon, null);
                        $verifDiagCommon->stopPhp('limit', 5_000_000_000);
                    },
                    static function () use ($verifDiagCommon, $stopReasonProp): void {
                        $stopReasonProp->setValue($verifDiagCommon, null);
                        $verifDiagCommon->stopNative('limit', 5_000_000_000);
                    },
                ],
                'uncommon' => [
                    static function () use ($verifDiagUncommon, $stopReasonProp): void {
                        $stopReasonProp->setValue($verifDiagUncommon, null);
                        $verifDiagUncommon->stopPhp('initialization_unavailable', 4_000_000_000_000);
                    },
                    static function () use ($verifDiagUncommon, $stopReasonProp): void {
                        $stopReasonProp->setValue($verifDiagUncommon, null);
                        $verifDiagUncommon->stopNative('initialization_unavailable', 4_000_000_000_000);
                    },
                ],
            ],
            'VerificationRunDiagnostics::report' => [
                'common' => [
                    static fn (): array => $verifDiagCommon->reportPhp(8_000_000_000),
                    static fn (): ?array => $verifDiagCommon->reportNative(8_000_000_000),
                ],
                'uncommon' => [
                    static fn (): array => $verifDiagUncommon->reportPhp(5_000_000_000_000),
                    static fn (): ?array => $verifDiagUncommon->reportNative(5_000_000_000_000),
                ],
            ],
            'PublicationForwarderDiagnostics::stop' => [
                'common' => [
                    static function () use ($pubDiagCommon, $pubStopProp): void {
                        $pubStopProp->setValue($pubDiagCommon, null);
                        $pubDiagCommon->stopPhp('passes');
                    },
                    static function () use ($pubDiagCommon, $pubStopProp): void {
                        $pubStopProp->setValue($pubDiagCommon, null);
                        $pubDiagCommon->stopNative('passes');
                    },
                ],
                'uncommon' => [
                    static function () use ($pubDiagUncommon, $pubStopProp): void {
                        $pubStopProp->setValue($pubDiagUncommon, null);
                        $pubDiagUncommon->stopPhp('unrecognized_stop_reason');
                    },
                    static function () use ($pubDiagUncommon, $pubStopProp): void {
                        $pubStopProp->setValue($pubDiagUncommon, null);
                        $pubDiagUncommon->stopNative('unrecognized_stop_reason');
                    },
                ],
            ],
            'PublicationForwarderDiagnostics::outcome' => [
                'common' => [
                    static function () use ($pubDiagCommon, $pubOutcomesProp): void {
                        $pubOutcomesProp->setValue($pubDiagCommon, []);
                        $pubDiagCommon->outcomePhp('published');
                    },
                    static function () use ($pubDiagCommon, $pubOutcomesProp): void {
                        $pubOutcomesProp->setValue($pubDiagCommon, []);
                        $pubDiagCommon->outcomeNative('published');
                    },
                ],
                'uncommon' => [
                    static function () use ($pubDiagUncommon, $pubOutcomesProp): void {
                        $pubOutcomesProp->setValue($pubDiagUncommon, []);
                        $pubDiagUncommon->outcomePhp('unrecognized_outcome_label');
                    },
                    static function () use ($pubDiagUncommon, $pubOutcomesProp): void {
                        $pubOutcomesProp->setValue($pubDiagUncommon, []);
                        $pubDiagUncommon->outcomeNative('unrecognized_outcome_label');
                    },
                ],
            ],
            'PublicationForwarderDiagnostics::report' => [
                'common' => [
                    static fn (): array => $pubDiagCommon->reportPhp(5_000_000_000),
                    static fn (): ?array => $pubDiagCommon->reportNative(5_000_000_000),
                ],
                'uncommon' => [
                    static fn (): array => $pubDiagUncommon->reportPhp(50_000_000_000),
                    static fn (): ?array => $pubDiagUncommon->reportNative(50_000_000_000),
                ],
            ],
            'SizeUnit::toBytes' => [
                'common' => [
                    static fn (): int => SizeUnit::toBytesPhp('250', 'MB'),
                    static fn (): ?int => SizeUnit::toBytesNative('250', 'MB'),
                ],
                'uncommon' => [
                    static fn (): int => SizeUnit::toBytesPhp('123.456', 'GB'),
                    static fn (): ?int => SizeUnit::toBytesNative('123.456', 'GB'),
                ],
            ],
            'SizeUnit::fromBytes' => [
                'common' => [
                    static fn (): array => SizeUnit::fromBytesPhp(1073741824),
                    static fn (): ?array => SizeUnit::fromBytesNative(1073741824),
                ],
                'uncommon' => [
                    static fn (): array => SizeUnit::fromBytesPhp(1500000),
                    static fn (): ?array => SizeUnit::fromBytesNative(1500000),
                ],
            ],
            'SqlError::isTransientLock' => [
                'common' => [
                    static fn (): bool => SqlError::isTransientLockPhp($queryExCommon),
                    static fn (): ?bool => SqlError::isTransientLockNative($queryExCommon),
                ],
                'uncommon' => [
                    static fn (): bool => SqlError::isTransientLockPhp($queryExUncommon),
                    static fn (): ?bool => SqlError::isTransientLockNative($queryExUncommon),
                ],
            ],
            'SqlError::describe' => [
                'common' => [
                    static fn (): string => SqlError::describePhp($queryExCommon),
                    static fn (): ?string => SqlError::describeNative($queryExCommon),
                ],
                'uncommon' => [
                    static fn (): string => SqlError::describePhp($queryExUncommon),
                    static fn (): ?string => SqlError::describeNative($queryExUncommon),
                ],
            ],
            'RelyingPartyIdResolver::firstHost' => [
                'common' => [
                    static fn (): string => RelyingPartyIdResolver::firstHostPhp('auth.example.com:8443'),
                    static fn (): ?string => RelyingPartyIdResolver::firstHostNative('auth.example.com:8443'),
                ],
                'uncommon' => [
                    static fn (): string => RelyingPartyIdResolver::firstHostPhp('  '.str_repeat('sub.', 15).'auth.example.com:8443 , proxy1.example.com, proxy2.example.com '),
                    static fn (): ?string => RelyingPartyIdResolver::firstHostNative('  '.str_repeat('sub.', 15).'auth.example.com:8443 , proxy1.example.com, proxy2.example.com '),
                ],
            ],
            'RelyingPartyIdResolver::isValidRelyingPartyId' => [
                'common' => [
                    static fn (): bool => RelyingPartyIdResolver::isValidRelyingPartyIdPhp('auth.example.com'),
                    static fn (): ?bool => RelyingPartyIdResolver::isValidRelyingPartyIdNative('auth.example.com'),
                ],
                'uncommon' => [
                    static fn (): bool => RelyingPartyIdResolver::isValidRelyingPartyIdPhp('127.0.0.1'),
                    static fn (): ?bool => RelyingPartyIdResolver::isValidRelyingPartyIdNative('127.0.0.1'),
                ],
            ],
            'RelyingPartyIdResolver::resolve' => [
                'common' => [
                    static fn (): string => RelyingPartyIdResolver::resolvePhp($reqCommon),
                    static fn (): ?string => RelyingPartyIdResolver::resolveNative($reqCommon),
                ],
                'uncommon' => [
                    static fn (): string => RelyingPartyIdResolver::resolvePhp($reqUncommon),
                    static fn (): ?string => RelyingPartyIdResolver::resolveNative($reqUncommon),
                ],
            ],
        ];
    }

    private function makeCollectionsCleaner(): CollectionsCleaningService
    {
        return new class extends CollectionsCleaningService
        {
            public function __construct()
            {
                $this->e0 = self::REGEX_FILE_EXTENSIONS;
                $this->e1 = self::REGEX_FILE_EXTENSIONS.self::REGEX_END;
                $this->e2 = self::REGEX_FILE_EXTENSIONS.self::REGEX_SUBJECT_SIZE.self::REGEX_END;
                $this->_regexes = new class extends RegexService
                {
                    public function tryRegex(string $subject, string $groupName): string
                    {
                        return '';
                    }
                };
            }
        };
    }

    private function makeQueryException(string $sqlstate, int $driverCode, string $message): QueryException
    {
        $pdo = new PDOException($message);
        $pdo->errorInfo = [$sqlstate, $driverCode, $message];

        return new QueryException('mariadb', 'INSERT INTO collections VALUES (?)', ['payload'], $pdo);
    }
}
