<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Monitoring\Collectors\HorizonCollector;
use App\Services\Monitoring\Collectors\ReleaseVerificationCollector;
use App\Services\Monitoring\Collectors\ServiceStatusCollector;
use App\Services\Monitoring\Collectors\TmuxSnapshotCollector;
use App\Services\Monitoring\GrafanaEmbedService;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Tests\TestCase;

final class RustMonitoringHorizonVerificationStatusTmuxGrafanaEmbedW68ParityTest extends TestCase
{
    /** Per class: the batch entry, its Rust export, its FFI declaration constant, its trailer constant and the trailer word count. */
    private const TARGETS = [
        HorizonCollector::class => ['w68HorizonCollectorPlanBatch', 'nntmux_w683_horizon_collector_batch', 'W68_HORIZON_COLLECTOR_FFI_CDEF', 'W68_HORIZON_COLLECTOR_TRAILER', 23],
        ReleaseVerificationCollector::class => ['w68ReleaseVerificationCollectorPlanBatch', 'nntmux_w683_release_verification_collector_batch',
            'W68_RELEASE_VERIFICATION_COLLECTOR_FFI_CDEF', 'W68_RELEASE_VERIFICATION_COLLECTOR_TRAILER', 36],
        ServiceStatusCollector::class => ['w68ServiceStatusCollectorPlanBatch', 'nntmux_w683_service_status_collector_batch', 'W68_SERVICE_STATUS_COLLECTOR_FFI_CDEF',
            'W68_SERVICE_STATUS_COLLECTOR_TRAILER', 29],
        TmuxSnapshotCollector::class => ['w68TmuxSnapshotCollectorPlanBatch', 'nntmux_w683_tmux_snapshot_collector_batch', 'W68_TMUX_SNAPSHOT_COLLECTOR_FFI_CDEF',
            'W68_TMUX_SNAPSHOT_COLLECTOR_TRAILER', 24],
        GrafanaEmbedService::class => ['w68GrafanaEmbedServicePlanBatch', 'nntmux_w683_grafana_embed_service_batch', 'W68_GRAFANA_EMBED_SERVICE_FFI_CDEF',
            'W68_GRAFANA_EMBED_SERVICE_TRAILER', 30],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        HorizonCollector::class => true,
        ReleaseVerificationCollector::class => true,
        ServiceStatusCollector::class => true,
        TmuxSnapshotCollector::class => true,
        GrafanaEmbedService::class => true,
    ];

    /** Shift and mask of each class's per-item decision field. */
    private const DECISION_FIELD = [
        HorizonCollector::class => [16, 7],
        ReleaseVerificationCollector::class => [4, 7],
        ServiceStatusCollector::class => [19, 7],
        TmuxSnapshotCollector::class => [19, 7],
        GrafanaEmbedService::class => [16, 7],
    ];

    /** One well-formed NUL-free item per class, in the order of TARGETS. */
    private const SAMPLES = [
        'supervisor-1|running|default|120|30|4|60',
        'verified|1048576|420|1|30',
        'redis|operational|12|up|ok|10',
        'nntmux:1.0|update_binaries|30|5|100|1',
        'nntmux-overview|4|now-6h|now|dark|tv|1',
    ];

    /** Edge cases shared by every helper: separators, whitespace, NUL and control bytes, non-ASCII, boundary and malformed items. */
    private const EDGE = ['', ' ', '  ', "\t", "\0", "\n", "\r", "\x0B", "\x0C", "\r\n", " \t\n\t ", "\x01", "\x7f", "\xff", "caf\xc3\xa9", '|', '||', '|||',
        '||||', '|||||', '||||||', '|||||||', ' | | | | | | ', "\t|\t|\t", "\0|\0|\0|\0|\0|\0", "\x0C|\x0C|\x0C|\x0C|\x0C", 'a|b|c|d|e|f|g|h|i', '.', '-', '_',
        ':', '/', '//', ';', ',', '@', '0', '00', '01', '1', '2', '3', '4', '5', '9', '10', '59', '60', '61', '99', '100', '249', '250', '299', '300',
        '301', '899', '900', '901', '999', '1000', '1001', '3599', '3600', '3601', '4999', '5000', '9999', '10000', '10001', '21600', '86400', '99999',
        '999999', '1000000', '9999999', '999999999', '1000000000', '9999999999', '99999999999', '999999999999', '1000000000000', '9999999999999',
        '10000000000000', '999999999999999', '1000000000000000', '-1', '+1', ' 12 ', '1e3', '0x10', '0.5', 'running', 'RUNNING', ' Running ', 'paused',
        'inactive', 'terminating', 'stopped', 'default', 'release-fingerprint', 'Release-Fingerprint', 'backfill:slow', 'queue name', 'q!', 'verified',
        'Verified', 'ready', 'pending', 'waiting_upload', 'verifying', 'unknown', 'header_rejected', 'dirty', 'unrepairable', 'quarantined', 'failed',
        'lost', 'operational', 'Degraded', 'partial_outage', 'major_outage', 'maintenance', 'outage', 'true', 'TRUE', 'yes', 'up', 'ok', 'pass', 'false',
        'no', 'down', 'fail', 'maybe', 'update_binaries', 'backfill', 'releases', 'postprocess', 'fixnames', 'removecrap', 'predb', 'sharing', 'idle',
        'monitor', 'IDLE', 'nntmux:1.0', 'nntmux:0.0', 'nntmux:9999.9999', 'nntmux:10000.1', 'nntmux:1', 'nntmux:.1', ':1.0', 'nn tmux:1.0',
        "nn\xc3\xa9:1.0", 'NNTMUX_2-a:12.3', 'nntmux:1.0 ', 'now', 'NOW', 'now-6h', 'now-1h/h', 'now/d', 'now-999999y', 'now-1000000s', 'now-0s',
        'now-1x', 'now+1h', 'now-h', 'now-1H', 'now-1M', 'now-1m', '1700000000000', '1700003600000', 'light', 'dark', 'system', 'Dark', 'blue', 'kiosk',
        'tv', 'TV', 'off', 'nntmux-overview', 'Host_Metrics', ...self::SAMPLES,
        'sup-a|paused|default|10|0|4|', 'sup-b|running|bulk|5|0|0|', 'sup-b|running|idle|0|0|1|', 'sup-c|running|slow|10|400|2|',
        'sup-c|running|release-fingerprint|50|0|1|', 'sup-c|bogus|default|1|1|1|1', 'SUP-A|running|bad queue!|1|1|1|', 'sup|running|q|99999|59|1|1',
        'pending|0|0|0|7200', 'dirty|100|50|2|', 'quarantined|100|50|3|', 'failed|100|20000|5|', '|1|1|1|1', 'verified|x|1|1|1', 'nope|1|1|1|1',
        'verifying|1|0|999|3600', 'db|degraded|300|1|1|10', 'api|operational|20|1|down|10', 'mail|maintenance||||', 'web|operational|20|1|1|9999',
        '|operational|1|1|1|1', 'x|operational|1|maybe|1|1', 'x|major_outage|5000|0|1|300', 'nntmux:2.0|releases|30|0|100|0', 'nntmux:3.0|idle|30|0|0|',
        'nntmux:4.0|postprocess|7200|0|0|', 'other:1.1|fixnames|1800|9|0|0', '|backfill|1|1|1|1', 'nntmux:x|backfill|1|1|1|1', 'a:1.1|predb|0|0|0|2',
        'nntmux-overview||now-6h|now|dark||1', 'abc|||||', 'abc||now|now-1h|||', 'abc||now-1h|1700000000000|||', '||now|now|||', 'bad uid!||||||',
        'abc||1700000000000|1700003600000|||', 'abc|0|now-1h|now|||', 'abc|1|now-1h|now|||0', 'abc|1|now-1h|now|blue||', 'abc|1|now-1h|now||sometimes|'];

    /**
     * EDGE plus long runs and boundary lengths.
     *
     * @return list<string>
     */
    private static function edgeCases(): array
    {
        return [...self::EDGE, str_repeat('u', 40), str_repeat('u', 41), str_repeat('a', 63), str_repeat('a', 64), str_repeat('a', 65), str_repeat('a', 255), str_repeat('a', 256), str_repeat('a ', 300),
            str_repeat('.', 600), str_repeat('|', 300), str_repeat('9', 40), str_repeat('9', 4), str_repeat('9', 5), str_repeat('9', 9), str_repeat('9', 10),
            str_repeat('9', 12), str_repeat('9', 13), str_repeat('9', 15), str_repeat('9', 16), str_repeat("\xc3\xa9", 200), str_repeat("\n", 300),
            str_repeat('f', 5000), str_repeat(':', 100), str_repeat('_', 300), str_repeat(' - ', 100), 'sup|running|'.str_repeat('q', 64).'|1|1|1|',
            'sup|running|'.str_repeat('q', 65).'|1|1|1|', str_repeat('s', 300).':1.0|releases|1|1|1|1', str_repeat('x', 4096).'|operational|1|1|1|1',
            str_repeat('u', 40).'|1|now-1h|now|light|tv|999999999', str_repeat('u', 300).'|||||', str_repeat("\xff", 300)];
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $class::resetW68RustFfi();
        }
        parent::tearDown();
    }

    public function test_w68_methods_and_constants_exist(): void
    {
        $missing = [];
        foreach (self::TARGETS as $class => [$entry, , $cdef, $trailerConstant, $trailer]) {
            $reflection = new ReflectionClass($class);
            foreach (['enableW68RustFfi', 'disableW68RustFfi', 'resetW68RustFfi', 'usesW68RustFfi', 'withW68RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isStatic() || ! $reflection->getMethod($method)->isPublic()
                    || $reflection->getMethod($method)->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$method;
                }
            }
            foreach (['W68_RUST_FFI_DEFAULT', $cdef, $trailerConstant] as $constant) {
                $declared = $reflection->getReflectionConstant($constant);
                if ($declared === false || ! $declared->isPublic() || $declared->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$constant;
                }
            }
            if (! \is_bool($reflection->getConstant('W68_RUST_FFI_DEFAULT')) || $reflection->getConstant($trailerConstant) !== $trailer) {
                $missing[] = $class.' constant values';
            }
            foreach (['w68RustFfiState', 'w68Invoke'] as $private) {
                $method = new ReflectionMethod($class, $private);
                if (! $method->isPrivate() || ! $method->isStatic() || $method->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$private;
                }
            }
            $state = new ReflectionMethod($class, 'w68RustFfiState');
            $params = array_map(static fn ($p): string => $p->getName().':'.$p->getType().'='.var_export($p->getDefaultValue(), true), $state->getParameters());
            if ($params !== ['op:?string=NULL', 'val:mixed=NULL'] || (string) $state->getReturnType() !== 'mixed') {
                $missing[] = $class.'::w68RustFfiState signature';
            }
            $uses = new ReflectionMethod($class, 'usesW68RustFfi');
            $with = new ReflectionMethod($class, 'withW68RustFfi');
            $withParams = array_map(static fn ($p): string => $p->getName().':'.$p->getType(), $with->getParameters());
            if (! $uses->getReturnType() instanceof ReflectionNamedType || $uses->getReturnType()->getName() !== 'bool' || $uses->getNumberOfParameters() !== 0
                || $withParams !== ['enabled:bool', 'callback:callable'] || (string) $with->getReturnType() !== 'mixed') {
                $missing[] = $class.' usesW68RustFfi/withW68RustFfi signatures';
            }
            foreach (['enableW68RustFfi', 'disableW68RustFfi', 'resetW68RustFfi'] as $toggle) {
                $method = new ReflectionMethod($class, $toggle);
                if ($method->getNumberOfParameters() !== 0 || (string) $method->getReturnType() !== 'void') {
                    $missing[] = $class.'::'.$toggle.' signature';
                }
            }
        }
        $this->assertSame(array_column(array_values(self::TARGETS), 1), array_values(array_unique(array_column(array_values(self::TARGETS), 1))));
        $this->assertSame([], $missing);
    }

    public function test_w68_adds_no_properties(): void
    {
        // Property sets as of Wave 67 (static and instance), so Wave 68 cannot add state outside w68RustFfiState().
        $expected = [
            HorizonCollector::class => ['ffi', 'jobs', 'masters', 'metrics', 'useRustFfi', 'workload'],
            ReleaseVerificationCollector::class => ['doubleSlots', 'ffi', 'int32Slots', 'int64Slots', 'queries', 'slotOwner', 'useRustFfi'],
            ServiceStatusCollector::class => ['doubleSlots', 'ffi', 'int32Slots', 'int64Slots', 'slotOwner', 'useRustFfi'],
            TmuxSnapshotCollector::class => ['ffi', 'snapshot', 'useRustFfi'],
            GrafanaEmbedService::class => ['ffi', 'issuer', 'useRustFfi'],
        ];
        foreach ($expected as $class => $names) {
            $actual = array_map(static fn ($p): string => $p->getName(), (new ReflectionClass($class))->getProperties());
            sort($actual);
            sort($names);
            $this->assertSame($names, $actual, $class);
            $this->assertSame([], preg_grep('/w68/i', $actual), $class);
        }
    }

    public function test_w68_defaults_and_toggles(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], (new ReflectionClass($class))->getConstant('W68_RUST_FFI_DEFAULT'), $class);
            $this->assertSame(self::DEFAULTS[$class], $class::usesW68RustFfi(), $class);
            $this->assertTrue($class::withW68RustFfi(true, static fn (): bool => $class::usesW68RustFfi()));
            $this->assertFalse($class::withW68RustFfi(false, static fn (): bool => $class::usesW68RustFfi()));
            $this->assertTrue($class::withW68RustFfi(false, static fn (): bool => $class::withW68RustFfi(true, static fn (): bool => $class::usesW68RustFfi())));
            $this->assertFalse($class::withW68RustFfi(true, static fn (): bool => $class::withW68RustFfi(false, static fn (): bool => $class::usesW68RustFfi())));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW68RustFfi(), $class);
            try {
                $class::withW68RustFfi(! $class::usesW68RustFfi(), $this->throwing('boom'));
                $this->fail('expected the callback exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertSame(self::DEFAULTS[$class], $class::usesW68RustFfi(), $class.' restores after an exception');
            $class::disableW68RustFfi();
            $this->assertFalse($class::usesW68RustFfi(), $class);
            $this->assertTrue($class::withW68RustFfi(true, static fn (): bool => $class::usesW68RustFfi()));
            $this->assertFalse($class::usesW68RustFfi(), $class.' scoped toggle restores an explicit disable');
            $class::enableW68RustFfi();
            $this->assertTrue($class::usesW68RustFfi(), $class);
            $class::resetW68RustFfi();
            $this->assertSame(self::DEFAULTS[$class], $class::usesW68RustFfi(), $class.' reset restores the default');
        }
        // Toggles are independent between classes.
        foreach (array_keys(self::TARGETS) as $class) {
            $flipped = ! $class::usesW68RustFfi();
            $class::withW68RustFfi($flipped, function () use ($class, $flipped): void {
                foreach (array_keys(self::TARGETS) as $other) {
                    $this->assertSame($other === $class ? $flipped : self::DEFAULTS[$other], $other::usesW68RustFfi(), $class.' vs '.$other);
                }
            });
            $class::disableW68RustFfi();
            foreach (array_keys(self::TARGETS) as $other) {
                $this->assertSame($other === $class ? false : self::DEFAULTS[$other], $other::usesW68RustFfi(), $class.' disable vs '.$other);
            }
            $class::resetW68RustFfi();
        }
        // Both dispatch paths return the PHP reference words, also after a reset drops the cached library.
        foreach (self::TARGETS as $class => [$entry]) {
            $items = [...self::SAMPLES, ''];
            $php = $class::{$entry.'Php'}($items);
            $this->assertSame($php, $class::withW68RustFfi(true, static fn (): array => $class::$entry($items)), $class);
            $this->assertSame($php, $class::withW68RustFfi(false, static fn (): array => $class::$entry($items)), $class);
            $class::resetW68RustFfi();
            $this->assertSame($php, $class::withW68RustFfi(true, static fn (): array => $class::$entry($items)), $class.' after reset');
            $this->assertSame($class::{$entry.'Php'}([]), $class::withW68RustFfi(true, static fn (): array => $class::$entry([])), $class);
        }
    }

    public function test_w68_existing_public_api_is_unchanged(): void
    {
        // Earlier-wave toggles keep their own defaults.
        $this->assertFalse(HorizonCollector::usesRustFfi());
        $this->assertTrue(HorizonCollector::usesW57RustFfi());
        $this->assertFalse(ReleaseVerificationCollector::usesRustFfi());
        $this->assertTrue(ReleaseVerificationCollector::usesW57RustFfi());
        $this->assertFalse(ServiceStatusCollector::usesRustFfi());
        $this->assertTrue(ServiceStatusCollector::usesW57RustFfi());
        $this->assertTrue(TmuxSnapshotCollector::usesW57RustFfi());
        $this->assertTrue(GrafanaEmbedService::usesW58RustFfi());
        foreach ([true, false] as $enabled) {
            foreach (array_keys(self::TARGETS) as $class) {
                $class::withW68RustFfi($enabled, function (): void {
                    $this->assertTrue(HorizonCollector::shouldSkipHorizonQueuePhp('release-fingerprint', 'rust'));
                    $this->assertSame(HorizonCollector::shouldSkipHorizonQueuePhp('default', 'release-fingerprint'),
                        HorizonCollector::shouldSkipHorizonQueue('default', 'release-fingerprint'));
                    $this->assertSame(HorizonCollector::anyMasterSupervisorRunningPhp(['paused', 'running']), HorizonCollector::anyMasterSupervisorRunning(['paused', 'running']));
                    $this->assertSame(ReleaseVerificationCollector::isQueryTimeoutErrorCodePhp(3024), ReleaseVerificationCollector::isQueryTimeoutErrorCode(3024));
                    $this->assertSame(ReleaseVerificationCollector::computeBacklogAgeSecondsPhp(null, 100), ReleaseVerificationCollector::computeBacklogAgeSeconds(null, 100));
                    $this->assertSame(ServiceStatusCollector::planStatusBatchPhp(['operational', 'degraded'], [12, null]),
                        ServiceStatusCollector::planStatusBatch(['operational', 'degraded'], [12, null]));
                    $this->assertSame(TmuxSnapshotCollector::planCountKeyBatchPhp(['releases', 'binaries'], 100, 200, 60),
                        TmuxSnapshotCollector::planCountKeyBatch(['releases', 'binaries'], 100, 200, 60));
                    $this->assertSame('https://grafana.example', GrafanaEmbedService::frameOriginPhp('https://grafana.example/grafana', 'https://nntmux.example'));
                    $this->assertSame(GrafanaEmbedService::frameOriginPhp('https://grafana.example/grafana', 'https://nntmux.example'),
                        GrafanaEmbedService::frameOrigin('https://grafana.example/grafana', 'https://nntmux.example'));
                });
            }
        }
    }

    public function test_w68_exports_resolve_and_guard_their_buffers(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, $symbol, $cdef, , $trailer]) {
            $declaration = \constant($class.'::'.$cdef);
            $this->assertStringContainsString($symbol, $declaration);
            $ffi = RustPipelineLibrary::resolveDomain('monitoring_w683', $declaration);
            $this->assertInstanceOf(FFI::class, $ffi, $class);
            $out = $ffi->new('int64_t[128]');
            $items = self::SAMPLES;
            $blob = implode("\0", $items);
            $words = $trailer + \count($items);
            $this->assertSame($words, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, \count($items), $out, 128), $class);
            $this->assertSame($class::{$entry.'Php'}($items), array_values((array) unpack('P*', FFI::string($out, $words * 8))), $class);
            // A length column splits unseparated items, so NUL bytes stay inside an item.
            $nul = ["a\0b", 'x|y', "x\0"];
            $lens = $ffi->new('int64_t[3]');
            FFI::memcpy($lens, pack('P*', 3, 3, 2), 24);
            $joined = implode('', $nul);
            $this->assertSame($trailer + 3, $this->callExport($ffi, $symbol, $joined, \strlen($joined), FFI::addr($lens[0]), 3, $out, 128), $class);
            $this->assertSame($class::{$entry.'Php'}($nul), array_values((array) unpack('P*', FFI::string($out, ($trailer + 3) * 8))), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, $joined, \strlen($joined) - 1, FFI::addr($lens[0]), 3, $out, 128), $class.' lengths must tile the blob');
            // A NUL inside an item, a short buffer, a null output, a null blob with a length and a count mismatch all decline.
            $this->assertSame(-1, $this->callExport($ffi, $symbol, "a\0b", 3, null, 1, $out, 128), $class);
            $this->assertSame(-$words, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, \count($items), $out, $words - 1), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, \count($items), null, 128), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, null, 8, null, 1, $out, 128), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, 'a', 1, null, 2, $out, 128), $class);
            $this->assertSame($trailer, $this->callExport($ffi, $symbol, null, 0, null, 0, $out, 128), $class);
            // Output overlapping the length column declines.
            $shared = $ffi->new('int64_t[64]');
            FFI::memcpy($shared, pack('P', 1), 8);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, 'a', 1, FFI::addr($shared[0]), 1, $shared, 64), $class.' overlap');
        }
    }

    public function test_w68_native_path_runs_for_every_class(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, , , , $trailer]) {
            $items = [...self::SAMPLES, "caf\xc3\xa9|operational|1|1|1|1", "caf\xc3\xa9", ''];
            $invoke = new ReflectionMethod($class, 'w68Invoke');
            $raw = $invoke->invoke(null, $items, $trailer + \count($items));
            $this->assertIsArray($raw, $class.' stays on Rust');
            $this->assertSame($class::{$entry.'Php'}($items), $raw, $class);
            $this->assertNull($invoke->invoke(null, $items, $trailer + \count($items) + 1), $class.' rejects a word count mismatch');
            $nul = ["a\0b", "sup|running|def\0ault|1|1|1|", "redis|operational|1\0|1|1|1", "\0"];
            $this->assertSame($class::{$entry.'Php'}($nul), $invoke->invoke(null, $nul, $trailer + 4), $class.' keeps NUL items on Rust through the length column');
            $this->assertSame($class::{$entry.'Php'}(["\0"]), $invoke->invoke(null, ["\0"], $trailer + 1), $class.' single NUL item');
            $this->assertSame($class::{$entry.'Php'}([]), $invoke->invoke(null, [], $trailer), $class.' empty batch');
            $this->assertCount($trailer, $class::{$entry.'Native'}([]), $class);
        }
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $items
     * @param  list<int>  $decisions  Expected per-item decisions.
     * @param  array<int, int>  $trailer  Expected trailer words by offset after the per-item words.
     * @param  list<array{int, int, int, int}>  $fields  Expected [item, shift, mask, value] per-item fields.
     */
    #[DataProvider('knownCases')]
    public function test_known_cases(string $class, array $items, array $decisions, array $trailer, array $fields = []): void
    {
        $words = $class::{self::TARGETS[$class][0].'Php'}($items);
        [$shift, $mask] = self::DECISION_FIELD[$class];
        $this->assertSame($decisions, array_map(static fn (int $word): int => $word >> $shift & $mask, \array_slice($words, 0, \count($items))));
        $tail = \array_slice($words, \count($items));
        $this->assertSame($trailer, array_intersect_key($tail, $trailer));
        foreach ($fields as [$item, $fieldShift, $fieldMask, $value]) {
            $this->assertSame($value, $words[$item] >> $fieldShift & $fieldMask, 'item '.$item.' field '.$fieldShift);
        }
        $this->assertParity($class, $items, 'known case');
    }

    /**
     * @return array<string, array{0: class-string, 1: list<string>, 2: list<int>, 3: array<int, int>, 4?: list<array{int, int, int, int}>}>
     */
    public static function knownCases(): array
    {
        return [
            'horizon: healthy, halted, stalled, idle, backlogged, skipped and malformed' => [HorizonCollector::class,
                ['sup-a|running|default|120|30|4|60', 'sup-a|paused|default|10|0|4|', 'sup-b|running|bulk|5|0|0|', 'sup-b|running|idle|0|0|1|',
                    'sup-c|running|slow|10|400|2|', 'sup-c|running|release-fingerprint|50|0|1|', 'sup-c|bogus|default|1|1|1|1', 'SUP-A|running|bad queue!|1|1|1|'],
                [5, 1, 2, 3, 4, 0, 6, 6],
                [0 => 1, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 2, 7 => 0, 8 => 6, 9 => 1, 12 => 1, 13 => 7, 14 => 0, 15 => 1, 16 => 16, 17 => 145,
                    18 => 11, 19 => 400, 20 => 5, 21 => 3, 22 => 5],
                [[0, 32, 0xFFFFF, 120], [0, 19, 7, 4], [1, 19, 7, 3], [5, 5, 1, 1], [7, 3, 3, 2]]],
            'horizon: drain estimate backlogs a slow queue' => [HorizonCollector::class, ['s|running|q|100|0|1|10', 's|running|q|100|0|1|20', 's|RUNNING|q|1|0|1|0'],
                [4, 4, 5], [4 => 2, 5 => 1, 19 => 0, 20 => 0, 21 => 1, 22 => 1], [[0, 32, 0xFFFFF, 600], [1, 32, 0xFFFFF, 300], [2, 32, 0xFFFFF, 0]]],
            'verification: buckets, histogram, stale and ratios' => [ReleaseVerificationCollector::class,
                ['verified|1048576|420|1|30', 'pending|0|0|0|7200', 'dirty|100|50|2|', 'quarantined|100|50|3|', 'failed|100|20000|5|', '|1|1|1|1',
                    'verified|x|1|1|1', 'nope|1|1|1|1'],
                [1, 2, 3, 4, 5, 0, 6, 6],
                [0 => 1, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 2, 29 => 20521, 30 => 1048877, 31 => 250000, 32 => 750000, 33 => 1, 34 => 5, 35 => 5],
                [[0, 15, 15, 3], [1, 19, 1, 1], [4, 15, 15, 8], [0, 28, 0xFFFFFF, 2438]]],
            'service: healthy, degraded, critical, maintenance, stale, skipped and malformed' => [ServiceStatusCollector::class,
                ['redis|operational|12|up|ok|10', 'db|degraded|300|1|1|10', 'api|operational|20|1|down|10', 'mail|maintenance||||', 'web|operational|20|1|1|9999',
                    '|operational|1|1|1|1', 'x|operational|1|maybe|1|1'],
                [5, 4, 3, 1, 2, 0, 6],
                [0 => 1, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 23 => 2, 24 => 5, 25 => 353, 26 => 300, 27 => 3, 28 => 6],
                [[0, 5, 7, 1], [1, 5, 7, 2], [2, 16, 3, 3], [4, 14, 1, 1], [6, 8, 3, 3]]],
            'service: latency levels and readiness raise severity' => [ServiceStatusCollector::class,
                ['a|operational|249|1|1|1', 'a|operational|4999|1|1|1', 'a|operational|5000|1|1|1', 'a|operational|1|false|1|300', 'a|major_outage|1|1|1|1'],
                [5, 4, 4, 4, 3], [19 => 1, 20 => 1, 21 => 2, 22 => 1, 23 => 4, 24 => 3, 27 => 5, 28 => 1], [[2, 16, 3, 2], [3, 16, 3, 2], [3, 14, 1, 0]]],
            'tmux: busy, ok, idle, dead, stalled, skipped and malformed' => [TmuxSnapshotCollector::class,
                ['nntmux:1.0|update_binaries|30|5|100|1', 'nntmux:2.0|releases|30|0|100|0', 'nntmux:3.0|idle|30|0|0|', 'nntmux:4.0|postprocess|7200|0|0|',
                    'other:1.1|fixnames|1800|9|0|0', '|backfill|1|1|1|1', 'nntmux:x|backfill|1|1|1|1'],
                [4, 5, 1, 2, 3, 0, 6],
                [7 => 5, 8 => 0, 9 => 1, 10 => 1, 18 => 15, 19 => 201, 20 => 7200, 21 => 4, 22 => 3, 23 => 2],
                [[0, 22, 255, 1], [4, 30, 255, 1], [0, 38, 0xFFFFF, 200], [1, 6, 7, 2], [3, 6, 7, 3]]],
            'grafana: dashboard, panel, defaults, inverted, mixed, skipped, malformed and absolute' => [GrafanaEmbedService::class,
                ['nntmux-overview||now-6h|now|dark||1', 'nntmux-overview|4|now-1h|now|light|tv|1', 'abc|||||', 'abc||now|now-1h|||', 'abc||now-1h|1700000000000|||',
                    '||now|now|||', 'bad uid!||||||', 'abc||1700000000000|1700003600000|||'],
                [3, 4, 3, 1, 2, 0, 5, 3],
                [0 => 1, 1 => 1, 2 => 1, 3 => 3, 4 => 1, 5 => 1, 24 => 2, 25 => 1, 26 => 21600, 28 => 2],
                [[2, 19, 1023, 39], [1, 19, 1023, 81], [1, 2, 3, 1], [7, 4, 3, 2], [4, 6, 3, 2]]],
            'grafana: zero panel, zero org, unknown theme and kiosk' => [GrafanaEmbedService::class,
                ['abc|0|now-1h|now|||', 'abc|1|now-1h|now|||0', 'abc|1|now-1h|now|blue||', 'abc|1|now-1h|now||sometimes|', 'abc|1|now-1h/h|now/d|SYSTEM|OFF|'],
                [5, 5, 5, 5, 4], [0 => 0, 4 => 1, 5 => 4, 25 => 1, 26 => 3600, 28 => 5], [[0, 2, 3, 2], [1, 14, 3, 2], [4, 8, 7, 3], [4, 11, 7, 3]]],
            'horizon empty batch' => [HorizonCollector::class, [], [], array_fill(0, 23, 0)],
            'verification empty batch' => [ReleaseVerificationCollector::class, [], [], array_fill(0, 36, 0)],
            'grafana empty batch' => [GrafanaEmbedService::class, [], [], array_fill(0, 30, 0)],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_edge_case_parity(string $class): void
    {
        $this->assertParity($class, [...self::SAMPLES, ...self::edgeCases()], 'edge batch');
        foreach (self::edgeCases() as $item) {
            $label = bin2hex(substr($item, 0, 64));
            $this->assertParity($class, [$item, $item], 'edge item '.$label);
            $this->assertParity($class, [self::SAMPLES[0], $item], 'edge item vs work item '.$label);
            $this->assertParity($class, [$item], 'single edge item '.$label);
        }
        // Edge values in every pipe field of every class's sample.
        $edges = self::edgeCases();
        foreach (self::SAMPLES as $sample) {
            $fields = explode('|', $sample);
            for ($slot = 0; $slot < \count($fields); $slot++) {
                $rows = [];
                foreach ($edges as $edge) {
                    $row = $fields;
                    $row[$slot] = $edge;
                    $rows[] = implode('|', $row);
                }
                $this->assertParity($class, $rows, 'edge field '.$slot.' of '.$sample);
            }
        }
        // Keys are ignored and the per-item words keep the input order.
        $keyed = ['b' => self::SAMPLES[1], 7 => self::SAMPLES[3], 'a' => '', 'c' => self::SAMPLES[4]];
        $this->assertParity($class, $keyed, 'keyed batch');
        $this->assertCount(self::TARGETS[$class][4] + 4, $class::{self::TARGETS[$class][0].'Php'}($keyed));
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_randomized_realistic_parity(string $class): void
    {
        // 3 seeds x 2 workloads x 320 items, plus 400 byte-soup items and 300 random slices per class.
        foreach ([681, 682, 683] as $seed) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity($class, $this->benchItems($class, new Randomizer(new Mt19937($seed)), $uncommon), 'seed '.$seed);
            }
        }
        $random = new Randomizer(new Mt19937(6833));
        $soup = [];
        for ($i = 0; $i < 400; $i++) {
            $length = $random->getInt(0, 64);
            $soup[] = $length === 0 ? '' : $random->getBytesFromString("aAnNoOwW0123456789||||::..  -_/!smhdMy\t\n\0\x0C\x01\x7f\xff\xc3\xa9runningupdownokidletvdark", $length);
        }
        $this->assertParity($class, $soup, 'byte soup');
        $this->assertParity($class, array_values(array_filter($soup, static fn (string $s): bool => ! str_contains($s, "\0"))), 'NUL-free soup');
        for ($i = 0; $i < 300; $i++) {
            $this->assertParity($class, \array_slice($soup, $i, $random->getInt(0, 5)), 'soup slice '.$i);
        }
        // Every class's generator through every helper.
        $mixed = [];
        foreach (array_keys(self::TARGETS) as $other) {
            array_push($mixed, ...\array_slice($this->benchItems($other, new Randomizer(new Mt19937(6834)), true), 0, 80));
        }
        $this->assertParity($class, $mixed, 'cross-class items');
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function targetClasses(): array
    {
        $cases = [];
        foreach (array_keys(self::TARGETS) as $class) {
            $cases[(new ReflectionClass($class))->getShortName()] = [$class];
        }

        return $cases;
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including joining, the FFI
        // call, decoding and any PHP fallback (release dylib, 5 warmups, NNTMUX_W683_BENCH_ROUNDS interleaved paired
        // rounds per helper and workload (default 301, minimum 60), 5 iterations per round). Common = one batch of 320
        // well-formed items of the class's format; uncommon = 320 items mixing edge cases, malformed, long, NUL, CR/LF,
        // case-changed and non-ASCII items. Each helper defaults to Rust only when it clears 1.5x on both workloads'
        // mean and p99, per .ai/rules/rust-performance.md; the assertion below requires every default to match its gates.
        // Speedup = PHP latency / Rust latency, minimum over 3 runs at 301 rounds (PHP 8.5 NTS, release dylib, Apple
        // Silicon, shared host at load average ~7.3-8.3), common mean / p99, uncommon mean / p99:
        // - HorizonCollector:             7.15x / 5.11x, 6.01x / 4.43x
        // - ReleaseVerificationCollector: 8.00x / 5.45x, 8.04x / 6.41x
        // - ServiceStatusCollector:       8.36x / 5.59x, 6.08x / 4.63x
        // - TmuxSnapshotCollector:        9.13x / 7.72x, 6.29x / 4.32x
        // - GrafanaEmbedService:          7.63x / 5.04x, 7.10x / 4.59x
        // Every helper clears every gate in all three runs and defaults to Rust.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // RUN_RUST_FFI_BENCHMARKS=1 php artisan test --compact tests/Unit/RustMonitoringHorizonVerificationStatusTmuxGrafanaEmbedW68ParityTest.php --filter benchmark
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            $this->markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the Wave 68.3 benchmark.');
        }
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(6836));
        $warmup = 5;
        $rounds = max(60, (int) (getenv('NNTMUX_W683_BENCH_ROUNDS') ?: 301));
        $iterations = 5;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => $this->benchItems($class, new Randomizer(new Mt19937(6837)), false),
                'uncommon' => $this->benchItems($class, new Randomizer(new Mt19937(6838)), true),
            ];
            foreach ($workloads as $name => $items) {
                $this->assertGreaterThanOrEqual(300, \count($items));
                $this->assertSame($class::{$entry.'Php'}($items), $class::{$entry.'Native'}($items), $class.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $sink += \count($class::withW68RustFfi($useRust, static fn (): array => $class::$entry($items)));
                    }
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $rounds; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $useRust ? $class::enableW68RustFfi() : $class::disableW68RustFfi();
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += \count($class::$entry($items));
                        }
                        $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                    }
                }
                $this->setOverride($class, null);
                $speedups[$class][$name] = [
                    'mean' => round($this->mean($times['php']) / $this->mean($times['rust']), 3),
                    'p99' => round($this->p99($times['php']) / $this->p99($times['rust']), 3),
                    'php_mean_us' => round($this->mean($times['php']), 2),
                    'rust_mean_us' => round($this->mean($times['rust']), 2),
                ];
            }
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES) ?: '';
        fwrite(STDERR, "\nRustMonitoringHorizonVerificationStatusTmuxGrafanaEmbedW68 speedup: {$summary}\n");
        foreach (array_keys(self::TARGETS) as $class) {
            $qualifies = true;
            foreach ($speedups[$class] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            $this->assertSame($qualifies, $class::usesW68RustFfi(), $class.' default must match its 1.5x gates: '.$summary);
        }
    }

    /**
     * @param  array<array-key, string>  $items
     */
    private function assertParity(string $class, array $items, string $message): void
    {
        $entry = self::TARGETS[$class][0];
        $php = $class::{$entry.'Php'}($items);
        $this->assertCount(self::TARGETS[$class][4] + \count($items), $php, $class.' '.$message);
        foreach ($php as $word) {
            $this->assertIsInt($word, $class.' '.$message);
            $this->assertGreaterThanOrEqual(0, $word, $class.' '.$message);
        }
        $this->assertSame($php, $class::{$entry.'Native'}($items), $class.' '.$message);
        $this->assertSame($php, $class::withW68RustFfi(true, static fn (): array => $class::$entry($items)), $class.' '.$message);
        $this->assertSame($php, $class::withW68RustFfi(false, static fn (): array => $class::$entry($items)), $class.' '.$message);
    }

    /**
     * 320 well-formed items in each class's format; uncommon batches mix in edge cases, malformed and long items,
     * case changes, NUL and non-ASCII bytes.
     *
     * @return list<string>
     */
    private function benchItems(string $class, Randomizer $random, bool $uncommon): array
    {
        $rows = [];
        for ($i = 0; $i < 320; $i++) {
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $rows[] = $this->pick($random, self::edgeCases());

                continue;
            }
            $row = match ($class) {
                HorizonCollector::class => $this->horizonItem($random, $uncommon),
                ReleaseVerificationCollector::class => $this->verificationItem($random, $uncommon),
                ServiceStatusCollector::class => $this->serviceItem($random, $uncommon),
                TmuxSnapshotCollector::class => $this->tmuxItem($random, $uncommon),
                default => $this->grafanaItem($random, $uncommon),
            };
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $row = $this->pick($random, [strtoupper($row), strtolower($row), ' '.$row, $row."\t", $row."\0x", "caf\xc3\xa9".$row, $row.'|x', $row.'||',
                    str_repeat($row, 4), str_replace('.', ' ', $row), str_replace('|', ' | ', $row), "\x01".$row, $row."\r\n", str_replace('-', '', $row),
                    str_replace(':', '_', $row)]);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function horizonItem(Randomizer $random, bool $noisy): string
    {
        return implode('|', [$this->pick($random, ['supervisor-1', 'supervisor-2', 'nntmux-supervisor', 'SUPERVISOR-1', '']),
            $this->pick($random, ['running', 'running', 'running', 'paused', 'inactive', 'terminating', $noisy ? 'Running ' : 'running']),
            $this->pick($random, ['default', 'releases', 'postprocess', 'fingerprint', 'release-fingerprint', 'notifications', 'backfill:slow', $noisy ? 'bad queue' : 'default']),
            $random->getInt(0, 50_000), $random->getInt(0, 1_200), $random->getInt(0, 32), $this->pick($random, ['', $random->getInt(0, 2_000), $random->getInt(1, 200)])]);
    }

    private function verificationItem(Randomizer $random, bool $noisy): string
    {
        return implode('|', [$this->pick($random, ['verified', 'verified', 'ready', 'pending', 'waiting_upload', 'verifying', 'unknown', 'header_rejected', 'dirty',
            'unrepairable', 'quarantined', 'failed', 'Verified', $noisy ? 'lost' : 'pending']), $random->getInt(0, 10_000_000_000),
            $this->pick($random, ['', $random->getInt(0, 60_000), $random->getInt(0, 1_000)]), $random->getInt(0, 9), $this->pick($random, ['', $random->getInt(0, 20_000)])]);
    }

    private function serviceItem(Randomizer $random, bool $noisy): string
    {
        $probe = ['1', 'true', 'up', 'ok', 'pass', 'yes', '0', 'false', 'down', 'fail', '', 'UP', $noisy ? 'maybe' : '1'];

        return implode('|', [$this->pick($random, ['redis', 'mariadb', 'manticore', 'horizon', 'nntp', 'web', 'Redis', 'scheduler', '']),
            $this->pick($random, ['operational', 'operational', 'degraded', 'partial_outage', 'major_outage', 'maintenance', 'Operational', $noisy ? 'outage' : 'operational']),
            $this->pick($random, ['', $random->getInt(0, 10_000), $random->getInt(0, 300)]), $this->pick($random, $probe), $this->pick($random, $probe),
            $this->pick($random, ['', $random->getInt(0, 2_000), $random->getInt(0, 300)])]);
    }

    private function tmuxItem(Randomizer $random, bool $noisy): string
    {
        return implode('|', [$this->pick($random, ['nntmux:'.$random->getInt(0, 12).'.'.$random->getInt(0, 3), 'nntmux:0.0', 'NNTPProxy:1.'.$random->getInt(0, 9), '',
            $noisy ? 'nntmux-1' : 'nntmux:1.0']), $this->pick($random, ['update_binaries', 'backfill', 'releases', 'postprocess', 'fixnames', 'removecrap', 'predb',
                'sharing', 'idle', 'monitor', 'IDLE', '', $noisy ? 'htop' : 'releases']), $this->pick($random, ['', $random->getInt(0, 10_000), $random->getInt(0, 600)]),
            $random->getInt(0, 100_000), $random->getInt(0, 100_000), $this->pick($random, ['', '0', '1', '1', $noisy ? 'yes' : '1'])]);
    }

    private function grafanaItem(Randomizer $random, bool $noisy): string
    {
        return implode('|', [$this->pick($random, ['nntmux-overview', 'queues', 'abc123', 'Host_Metrics', 'x'.$random->getInt(1, 9_999), '', $noisy ? 'bad uid' : 'queues']),
            $this->pick($random, ['', '', $random->getInt(1, 40), $noisy ? '0' : '2']),
            $this->pick($random, ['', 'now-6h', 'now-24h', 'now-7d', 'now-30m/m', 'now-'.$random->getInt(1, 999_999).'s', (string) $random->getInt(1_600_000_000_000, 1_700_000_000_000),
                $noisy ? 'yesterday' : 'now-1h']), $this->pick($random, ['', 'now', 'now', 'now/d', (string) $random->getInt(1_700_000_000_000, 1_800_000_000_000)]),
            $this->pick($random, ['', 'light', 'dark', 'system', 'Dark']), $this->pick($random, ['', '1', 'true', 'kiosk', 'tv', 'off', '0', $noisy ? 'full' : '']),
            $this->pick($random, ['', '1', $random->getInt(2, 9)])]);
    }

    /**
     * A callback that throws a RuntimeException with $message.
     *
     * @return callable(): mixed
     */
    private function throwing(string $message): callable
    {
        return static function () use ($message): mixed {
            throw new RuntimeException($message);
        };
    }

    /**
     * Calls a Wave 68.3 export by name.
     */
    private function callExport(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function setOverride(string $class, ?bool $enabled): void
    {
        (new ReflectionMethod($class, 'w68RustFfiState'))->invoke(null, 'set', $enabled);
    }

    private function requireLibrary(): void
    {
        if (! \extension_loaded('FFI') || RustPipelineLibrary::resolveDomain('monitoring_w683', GrafanaEmbedService::W68_GRAFANA_EMBED_SERVICE_FFI_CDEF) === null) {
            $this->markTestSkipped('libnntmux_pipeline is not available.');
        }
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, \count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(\count($values) - 1, (int) ceil(\count($values) * 0.99) - 1)];
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return T
     */
    private function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }
}
