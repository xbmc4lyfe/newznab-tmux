<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\CollectReleaseFingerprintMetadata;
use App\Services\Releases\Fingerprint\CompactFingerprintCollector;
use App\Services\Releases\Fingerprint\ReleaseFingerprintCollector;
use App\Services\Releases\Verification\CandidateAdmission;
use App\Services\Releases\Verification\PublicationOutcome;
use App\Services\Releases\Verification\ReleaseMetadataResolver;
use App\Services\Releases\Verification\VerificationResult;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class RustVerificationMetadataFingerprintJobParityTest extends TestCase
{
    private const CLASSES = [ReleaseMetadataResolver::class, PublicationOutcome::class, VerificationResult::class, CandidateAdmission::class, CollectReleaseFingerprintMetadata::class];

    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['nntmux' => ['fingerprint_worker' => 'rust', 'fingerprint_format' => 'compact']]));
        Container::setInstance($container);
        $this->enable(false);
    }

    protected function tearDown(): void
    {
        $this->enable(false);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function enable(bool $enabled): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi($enabled);
        }
    }

    private static function job(int $releaseId, string $generation = '', string $token = ''): CollectReleaseFingerprintMetadata
    {
        $job = new CollectReleaseFingerprintMetadata($releaseId, $generation);
        (new ReflectionProperty($job, 'runToken'))->setValue($job, $token);

        return $job;
    }

    /**
     * @param  list<mixed>  $args
     * @param  array<string, mixed>  $config
     * @return array{target: object|class-string, method: string, args: list<mixed>, config: array<string, mixed>}
     */
    private static function fixture(object|string $target, string $method, array $args = [], array $config = []): array
    {
        return ['target' => $target, 'method' => $method, 'args' => $args, 'config' => $config];
    }

    /** @param array{target: object|class-string, method: string, args: list<mixed>, config: array<string, mixed>} $fixture */
    private function prepare(array $fixture): void
    {
        config(array_replace(['nntmux.fingerprint_worker' => 'rust', 'nntmux.fingerprint_format' => 'compact'], $fixture['config']));
    }

    /** @param array{target: object|class-string, method: string, args: list<mixed>, config: array<string, mixed>} $fixture */
    private static function invoke(array $fixture, string $suffix = ''): mixed
    {
        $target = $fixture['target'];
        $method = $fixture['method'].$suffix;
        if (is_object($target)) {
            if ($method === 'claimToken') {
                return (new ReflectionMethod($target, $method))->invoke($target);
            }

            return $target->$method(...$fixture['args']);
        }

        return $target::$method(...$fixture['args']);
    }

    /** @param array{target: object|class-string, method: string, args: list<mixed>, config: array<string, mixed>} $fixture */
    private static function assertNative(array $fixture, mixed $expected): void
    {
        self::assertSame($expected, self::invoke($fixture, 'Native'), $fixture['method'].' native parity');
        if ($fixture['method'] === 'currentFormat' && $expected === null) {
            self::assertSame(['value' => null], (new ReflectionMethod(CollectReleaseFingerprintMetadata::class, 'computeNative'))->invoke(null, 8, [false, CompactFingerprintCollector::FORMAT]));
        }
    }

    /** @return list<array{target: object|class-string, method: string, args: list<mixed>, config: array<string, mixed>}> */
    private static function cases(): array
    {
        $cases = [];
        $manifest = ['subject' => '#A@B$C%D^E§F¨G©HÖI', 'fromname' => "'''poster'''", 'groups' => ['alt.test']];
        foreach ([null, '', 'cleaned', ['cleansubject' => '0', 'properlynamed' => false, 'predb' => 8, 'id' => 7], ['properlynamed' => true, 'predb' => -9], ['cleansubject' => '', 'properlynamed' => '0']] as $cleaned) {
            $cases[] = self::fixture(ReleaseMetadataResolver::class, 'normalizeManifestMetadata', [$manifest, $cleaned]);
        }
        foreach ([null, '', 'original', 17] as $original) {
            foreach ([true, false, 1, '1'] as $override) {
                $cases[] = self::fixture(ReleaseMetadataResolver::class, 'normalizeManifestMetadata', [['subject' => '0', 'fromname' => "'poster'", 'metadata_source' => 'import', 'original_subject' => $original, 'import_name_override' => $override, 'categories_id' => 2000], null]);
            }
        }
        foreach ([1, '2000', '+2000', ' 2000 ', "\t\n\v2000\r ", "\f2000", "2000\0", '02000', 0, -1, true, false, null, '', '1.0', PHP_INT_MAX, (string) PHP_INT_MAX] as $category) {
            $cases[] = self::fixture(ReleaseMetadataResolver::class, 'normalizeManifestMetadata', [['subject' => 'Title', 'fromname' => 'Poster', 'metadata_source' => 'import', 'categories_id' => $category], null]);
        }
        foreach ([['0', 'raw', false], ['0', 'raw', true], ['', 'raw', true], ["a\0b", 'raw', false], ['é日本語', '', false]] as $args) {
            $cases[] = self::fixture(ReleaseMetadataResolver::class, 'searchName', $args);
        }
        $statuses = ['published', 'filtered', 'metadata_review', 'deferred', 'stale', 'paused', 'retry', 'invalid', 'error', 'unresolved', 'transport', 'no_release'];
        foreach ($statuses as $status) {
            $cases[] = self::fixture(PublicationOutcome::class, 'isValidStatus', [$status]);
            foreach ([null, 0, -1, PHP_INT_MAX] as $id) {
                $outcome = new PublicationOutcome($status, $id, "error\0é");
                $cases[] = self::fixture($outcome, 'published');
                $cases[] = self::fixture($outcome, 'toArray');
            }
        }
        foreach (['', 'PUBLISHED', "published\0", 'unknown', 0, 1, true, false, null, [], ['published']] as $status) {
            $cases[] = self::fixture(PublicationOutcome::class, 'isValidStatus', [$status]);
        }
        $cases[] = self::fixture(new PublicationOutcome('future_status', PHP_INT_MIN, 'preserved'), 'toArray');
        foreach (['complete', 'repaired', 'sampled', 'failed', '', 'Complete', "complete\0"] as $verdict) {
            $cases[] = self::fixture(new VerificationResult($verdict, 'reason', ['nested' => [1]], true, true, true), 'passed');
        }
        foreach ([[1, 'identity', 1, 'hash'], [PHP_INT_MIN, "a\0b", PHP_INT_MAX, "\xff\0"], [0, '', -1, '']] as $args) {
            $cases[] = self::fixture(new CandidateAdmission(...$args), 'toArray');
        }
        foreach ([PHP_INT_MIN, -1, 0, 1, PHP_INT_MAX] as $id) {
            foreach (['', '0', "g\0\xff", str_repeat('generation:', 1024)] as $generation) {
                foreach (['', '0', "token\0\xff"] as $token) {
                    $job = self::job($id, $generation, $token);
                    $cases[] = self::fixture($job, 'uniqueId');
                    $cases[] = self::fixture($job, 'claimToken');
                }
            }
        }
        foreach (['rust', 'legacy'] as $worker) {
            foreach (['compact', 'full'] as $format) {
                $config = ['nntmux.fingerprint_worker' => $worker, 'nntmux.fingerprint_format' => $format];
                $compact = $worker === 'rust' || $format !== 'full';
                $marker = $compact ? CompactFingerprintCollector::FORMAT : null;
                $schema = $compact ? CompactFingerprintCollector::SCHEMA_VERSION : ReleaseFingerprintCollector::SCHEMA_VERSION;
                $cases[] = self::fixture(CollectReleaseFingerprintMetadata::class, 'currentFormat', [], $config);
                $cases[] = self::fixture(CollectReleaseFingerprintMetadata::class, 'currentSchemaVersion', [], $config);
                foreach ([['format' => $marker, 'schema_version' => $schema], ['schema_version' => $schema], ['format' => $marker, 'schema_version' => (string) $schema], ['format' => $marker, 'schema_version' => false], ['format' => false, 'schema_version' => $schema], [], null, false, 1, 'metadata'] as $metadata) {
                    $cases[] = self::fixture(CollectReleaseFingerprintMetadata::class, 'isCurrent', [$metadata], $config);
                }
            }
        }

        return $cases;
    }

    public function test_deterministic_helpers_match_php_and_real_dispatch(): void
    {
        foreach (self::cases() as $fixture) {
            $this->prepare($fixture);
            $expected = self::invoke($fixture, 'Php');
            $this->enable(true);
            self::assertNative($fixture, $expected);
            foreach ([false, true] as $enabled) {
                $this->enable($enabled);
                self::assertSame($expected, self::invoke($fixture));
            }
        }
    }

    public function test_normalization_preserves_literal_cleaning_import_and_cast_contracts(): void
    {
        $this->enable(true);
        $normalized = ReleaseMetadataResolver::normalizeManifestMetadata(['subject' => '#A@B$C%D^E§F¨G©HÖI', 'fromname' => "'''p'o'''"], null);
        self::assertSame('ABCDEFGHI', $normalized['rawName']);
        self::assertSame("p'o", $normalized['from']);
        self::assertSame('raw', ReleaseMetadataResolver::searchName('0', 'raw', false));
        self::assertSame('0', ReleaseMetadataResolver::searchName('0', 'raw', true));
        $import = ['subject' => 'Override', 'fromname' => "'poster'", 'metadata_source' => 'import', 'original_subject' => '', 'import_name_override' => true, 'categories_id' => '2000'];
        $normalized = ReleaseMetadataResolver::normalizeManifestMetadata($import, null);
        self::assertSame('', $normalized['rawName']);
        self::assertSame('Override', $normalized['name']);
        self::assertSame("'poster'", $normalized['from']);
        self::assertSame(2000, $normalized['explicitCategory']);
        foreach ([['cleansubject' => 12, 'properlynamed' => '0', 'predb' => '23', 'id' => '-7'], ['cleansubject' => false, 'predb' => '1e2', 'id' => 3.5]] as $cleaned) {
            self::assertSame(ReleaseMetadataResolver::normalizeManifestMetadataPhp($import, $cleaned), ReleaseMetadataResolver::normalizeManifestMetadata($import, $cleaned));
        }
        foreach (["bad\xffsubject", "\xc3(", "\x80\0"] as $invalid) {
            self::assertNull(ReleaseMetadataResolver::searchNameNative($invalid, 'raw', true));
            self::assertSame(ReleaseMetadataResolver::searchNamePhp($invalid, 'raw', true), ReleaseMetadataResolver::searchName($invalid, 'raw', true));
            $manifest = ['subject' => $invalid, 'fromname' => $invalid];
            self::assertNull(ReleaseMetadataResolver::normalizeManifestMetadataNative($manifest, null));
            self::assertSame(ReleaseMetadataResolver::normalizeManifestMetadataPhp($manifest, null), ReleaseMetadataResolver::normalizeManifestMetadata($manifest, null));
        }
    }

    public function test_object_state_readonly_serialization_and_legacy_job_defaults(): void
    {
        foreach ([false, true] as $enabled) {
            $this->enable($enabled);
            $outcome = unserialize(serialize(new PublicationOutcome('unknown', 0, "error\0")), ['allowed_classes' => [PublicationOutcome::class]]);
            self::assertSame(['status' => 'unknown', 'release_id' => 0, 'error' => "error\0"], $outcome->toArray());
            self::assertFalse($outcome->published());
            self::assertTrue((new PublicationOutcome('published', 0))->published());
            self::assertFalse((new PublicationOutcome('published'))->published());
            foreach ([new VerificationResult('sampled', 'r', ['file' => 'x'], true, true, true), new CandidateAdmission(7, "identity\0", 9, 'hash')] as $value) {
                $copy = unserialize(serialize($value), ['allowed_classes' => [get_class($value)]]);
                self::assertEquals($value, $copy);
                self::assertTrue((new ReflectionClass($copy))->isReadOnly());
                self::assertSame($value instanceof VerificationResult ? true : $value->toArrayPhp(), $copy instanceof VerificationResult ? $copy->passed() : $copy->toArray());
            }
            $job = self::job(PHP_INT_MIN, "generation\0", '');
            $copy = unserialize(serialize($job), ['allowed_classes' => [CollectReleaseFingerprintMetadata::class]]);
            self::assertSame($job->uniqueId(), $copy->uniqueId());
            self::assertSame($job->claimTokenPhp(), (new ReflectionMethod($copy, 'claimToken'))->invoke($copy));
            $legacy = (new ReflectionClass(CollectReleaseFingerprintMetadata::class))->newInstanceWithoutConstructor();
            $legacy->__unserialize(["\0".CollectReleaseFingerprintMetadata::class."\0releaseId" => 42]);
            self::assertSame('42', $legacy->uniqueId());
            self::assertSame(substr(hash('sha256', 'legacy:42:'), 0, 16), (new ReflectionMethod($legacy, 'claimToken'))->invoke($legacy));
        }
    }

    public function test_disabled_and_missing_library_fallback_and_pre_serialization_rejection(): void
    {
        foreach (self::cases() as $fixture) {
            $this->prepare($fixture);
            self::assertNull(self::invoke($fixture, 'Native'));
            self::assertSame(self::invoke($fixture, 'Php'), self::invoke($fixture));
        }
        $property = new ReflectionProperty(RustPipelineLibrary::class, 'domainLibraries');
        $saved = $property->getValue();
        try {
            $cache = $saved;
            foreach (self::CLASSES as $class) {
                $cache['nfo|default|'.hash('xxh3', $class::FFI_CDEF)] = false;
            }
            $property->setValue(null, $cache);
            $this->enable(true);
            foreach (self::cases() as $fixture) {
                $this->prepare($fixture);
                self::assertNull(self::invoke($fixture, 'Native'));
                self::assertSame(self::invoke($fixture, 'Php'), self::invoke($fixture));
            }
        } finally {
            $property->setValue(null, $saved);
        }
        $object = new class
        {
            public function __serialize(): array
            {
                throw new \RuntimeException('Object serialization must never run');
            }
        };
        $cycle = [];
        $cycle['self'] = &$cycle;
        $resource = fopen('php://memory', 'r+');
        self::assertIsResource($resource);
        try {
            foreach (self::CLASSES as $class) {
                foreach ([$object, $cycle, $resource] as $unsupported) {
                    self::assertNull((new ReflectionMethod($class, 'computeNative'))->invoke(null, 3, [$unsupported]));
                }
            }
            self::assertNull(PublicationOutcome::isValidStatusNative($object));
            self::assertFalse(PublicationOutcome::isValidStatus($object));
            self::assertFalse(CollectReleaseFingerprintMetadata::isCurrentNative($cycle));
            self::assertFalse(CollectReleaseFingerprintMetadata::isCurrent($cycle));
            self::assertNull(ReleaseMetadataResolver::normalizeManifestMetadataNative(['subject' => 'x', 'fromname' => 'p'], ['cleansubject' => $object]));
            $manifest = ['subject' => 'x', 'fromname' => 'p', 'payload' => $object];
            self::assertSame(ReleaseMetadataResolver::normalizeManifestMetadataPhp($manifest, null), ReleaseMetadataResolver::normalizeManifestMetadataNative($manifest, null));
            $metadata = ['format' => CompactFingerprintCollector::FORMAT, 'schema_version' => CompactFingerprintCollector::SCHEMA_VERSION, 'payload' => $object];
            config(['nntmux.fingerprint_worker' => 'rust']);
            self::assertTrue(CollectReleaseFingerprintMetadata::isCurrentNative($metadata));
        } finally {
            fclose($resource);
        }
    }

    public function test_c_abi_range_capacity_wire_and_byte_alignment_contract(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('nfo', ReleaseMetadataResolver::FFI_CDEF);
        self::assertNotNull($ffi);
        // PHP 8.5 FFI first-class callables crash; invoke the symbol through a closure.
        // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
        $call = static fn (int $op, mixed $input, int $length, mixed $out, int $capacity): int => $ffi->nntmux_w18_verification_metadata_fingerprint($op, $input, $length, $out, $capacity);
        $wire = serialize(['complete']);
        $out = $ffi->new('char[128]');
        FFI::memset($out, 88, 128);
        self::assertSame(-1, $call(5, null, strlen($wire), $out, 128));
        self::assertSame(-1, $call(5, $wire, 0, $out, 128));
        self::assertSame(-1, $call(5, $wire, PHP_INT_MAX, $out, 128));
        self::assertSame(-1, $call(5, $wire, -1, $out, 128));
        self::assertSame(-1, $call(5, $wire, strlen($wire), null, 128));
        self::assertSame(-1, $call(5, $wire, strlen($wire), $out, -1));
        self::assertSame(-1, $call(5, $wire, strlen($wire), $ffi->cast('char *', -16), PHP_INT_MAX));
        self::assertSame(-1, $call(5, $ffi->cast('char *', -1), 1, $out, 128));
        self::assertSame(-1, $call(5, $wire, strlen($wire), $ffi->cast('char *', -1), 1));
        self::assertSame(-1, $call(5, $out, 8, $out, 128));
        self::assertSame(-1, $call(5, $out, 8, FFI::addr($out[4]), 64));
        $required = strlen('b:1;');
        self::assertSame(-$required, $call(5, $wire, strlen($wire), null, 0));
        self::assertSame(-$required, $call(5, $wire, strlen($wire), $out, $required - 1));
        self::assertSame(str_repeat('X', 128), FFI::string($out, 128));
        self::assertSame($required, $call(5, $wire, strlen($wire), $out, $required));
        self::assertSame('b:1;', FFI::string($out, $required));
        self::assertSame(str_repeat('X', 128 - $required), FFI::string(FFI::addr($out[$required]), 128 - $required));
        $input = $ffi->new('char['.(strlen($wire) + 1).']');
        FFI::memcpy(FFI::addr($input[1]), $wire, strlen($wire));
        FFI::memset($out, 88, 128);
        self::assertSame($required, $call(5, FFI::addr($input[1]), strlen($wire), FFI::addr($out[1]), $required));
        self::assertSame('Xb:1;'.str_repeat('X', 123), FFI::string($out, 128));
        foreach (['N;', 'a:0:{}junk', 'a:1:{i:0;R:1;}', 'a:1:{i:0;r:1;}', 'a:1:{i:0;O:8:"stdClass":0:{}}', 'a:999999999999999999999999:{}', serialize([]), serialize(['complete', 'extra']), serialize([1]), serialize(['verdict' => 'complete'])] as $malformed) {
            FFI::memset($out, 88, 128);
            self::assertSame(-1, $call(5, $malformed, strlen($malformed), $out, 128));
            self::assertSame(str_repeat('X', 128), FFI::string($out, 128));
        }
        foreach ([0 => 7, 1 => 3, 2 => 2, 3 => 1, 4 => 3, 5 => 1, 6 => 4, 7 => 2, 8 => 2, 9 => 3, 10 => 3, 11 => 3] as $op => $arity) {
            foreach ([$arity - 1, $arity + 1] as $wrong) {
                $malformed = serialize(array_fill(0, $wrong, null));
                self::assertSame(-1, $call($op, $malformed, strlen($malformed), $out, 128));
            }
        }
        self::assertSame(-1, $call(999, $wire, strlen($wire), $out, 128));
    }

    /** @return array<string, array{common: list<array>, uncommon: list<array>}> */
    private static function workloads(): array
    {
        $long = str_repeat('long.é日本語.', 1024);
        $manifest = ['subject' => '#A@Title', 'fromname' => "'poster'"];
        $import = ['subject' => $long, 'fromname' => 'poster', 'metadata_source' => 'import', 'original_subject' => '', 'import_name_override' => true, 'categories_id' => '2000'];
        $full = ['nntmux.fingerprint_worker' => 'legacy', 'nntmux.fingerprint_format' => 'full'];
        $make = static fn (object|string $target, string $method, array $args = [], array $config = []): array => self::fixture($target, $method, $args, $config);

        return [
            'normalizeManifestMetadata' => ['common' => [$make(ReleaseMetadataResolver::class, 'normalizeManifestMetadata', [$manifest, null]), $make(ReleaseMetadataResolver::class, 'normalizeManifestMetadata', [$manifest, ['cleansubject' => 'Clean.Title', 'properlynamed' => true, 'predb' => 12, 'id' => 7]])], 'uncommon' => [$make(ReleaseMetadataResolver::class, 'normalizeManifestMetadata', [$import, null]), $make(ReleaseMetadataResolver::class, 'normalizeManifestMetadata', [['subject' => $long, 'fromname' => $long], ['cleansubject' => '0', 'predb' => PHP_INT_MAX, 'id' => PHP_INT_MIN]])]],
            'searchName' => ['common' => [$make(ReleaseMetadataResolver::class, 'searchName', ['Clean.Title', 'raw', false]), $make(ReleaseMetadataResolver::class, 'searchName', ['0', 'raw', true])], 'uncommon' => [$make(ReleaseMetadataResolver::class, 'searchName', [$long, 'raw', false]), $make(ReleaseMetadataResolver::class, 'searchName', ["name\0binary", $long, true])]],
            'published' => ['common' => [$make(new PublicationOutcome('published', 12), 'published'), $make(new PublicationOutcome('filtered'), 'published')], 'uncommon' => [$make(new PublicationOutcome($long, PHP_INT_MIN), 'published'), $make(new PublicationOutcome('published', 0), 'published')]],
            'isValidStatus' => ['common' => [$make(PublicationOutcome::class, 'isValidStatus', ['published']), $make(PublicationOutcome::class, 'isValidStatus', ['no_release'])], 'uncommon' => [$make(PublicationOutcome::class, 'isValidStatus', [$long]), $make(PublicationOutcome::class, 'isValidStatus', [false])]],
            'outcomeToArray' => ['common' => [$make(new PublicationOutcome('published', 12), 'toArray'), $make(new PublicationOutcome('error', null, 'error'), 'toArray')], 'uncommon' => [$make(new PublicationOutcome($long, PHP_INT_MIN, $long), 'toArray'), $make(new PublicationOutcome("unknown\0\xff", PHP_INT_MAX, "\0\xff"), 'toArray')]],
            'passed' => ['common' => [$make(new VerificationResult('complete', ''), 'passed'), $make(new VerificationResult('failed', ''), 'passed')], 'uncommon' => [$make(new VerificationResult($long, ''), 'passed'), $make(new VerificationResult("complete\0", ''), 'passed')]],
            'admissionToArray' => ['common' => [$make(new CandidateAdmission(12, 'identity', 1, 'hash'), 'toArray')], 'uncommon' => [$make(new CandidateAdmission(PHP_INT_MIN, $long, PHP_INT_MAX, $long), 'toArray'), $make(new CandidateAdmission(0, "\xff\0", -1, "\0\xff"), 'toArray')]],
            'uniqueId' => ['common' => [$make(self::job(12), 'uniqueId'), $make(self::job(12, 'generation'), 'uniqueId')], 'uncommon' => [$make(self::job(PHP_INT_MIN, $long), 'uniqueId'), $make(self::job(PHP_INT_MAX, "\xff\0"), 'uniqueId')]],
            'claimToken' => ['common' => [$make(self::job(12, '', 'token'), 'claimToken'), $make(self::job(12), 'claimToken')], 'uncommon' => [$make(self::job(PHP_INT_MIN, $long), 'claimToken'), $make(self::job(PHP_INT_MAX, "\xff\0", '0'), 'claimToken')]],
            'currentFormat' => ['common' => [$make(CollectReleaseFingerprintMetadata::class, 'currentFormat')], 'uncommon' => [$make(CollectReleaseFingerprintMetadata::class, 'currentFormat', [], $full)]],
            'currentSchemaVersion' => ['common' => [$make(CollectReleaseFingerprintMetadata::class, 'currentSchemaVersion')], 'uncommon' => [$make(CollectReleaseFingerprintMetadata::class, 'currentSchemaVersion', [], $full)]],
            'isCurrent' => ['common' => [$make(CollectReleaseFingerprintMetadata::class, 'isCurrent', [['format' => CompactFingerprintCollector::FORMAT, 'schema_version' => CompactFingerprintCollector::SCHEMA_VERSION]]), $make(CollectReleaseFingerprintMetadata::class, 'isCurrent', [null])], 'uncommon' => [$make(CollectReleaseFingerprintMetadata::class, 'isCurrent', [['schema_version' => ReleaseFingerprintCollector::SCHEMA_VERSION, 'payload' => array_fill(0, 512, $long)]], $full), $make(CollectReleaseFingerprintMetadata::class, 'isCurrent', [['format' => null, 'schema_version' => (string) ReleaseFingerprintCollector::SCHEMA_VERSION]], $full)]],
        ];
    }

    public function test_paired_randomized_benchmark(): void
    {
        if (getenv('NNTMUX_VERIFICATION_METADATA_FINGERPRINT_JOB_BENCH') !== '1') {
            self::markTestSkipped('Opt in with NNTMUX_VERIFICATION_METADATA_FINGERPRINT_JOB_BENCH=1');
        }
        $samples = 3000;
        $random = new Randomizer(new Mt19937(1802));
        $destination = '/tmp/nntmux-wave18-verification-metadata-fingerprint-job-benchmark.json';
        $root = dirname(__DIR__, 2);
        $paths = ['rust/pipeline-ffi/src/nfo.rs', 'app/Services/Releases/Verification/ReleaseMetadataResolver.php', 'app/Services/Releases/Verification/PublicationOutcome.php', 'app/Services/Releases/Verification/VerificationResult.php', 'app/Services/Releases/Verification/CandidateAdmission.php', 'app/Jobs/CollectReleaseFingerprintMetadata.php', 'tests/Unit/RustVerificationMetadataFingerprintJobParityTest.php', 'composer.lock', 'rust/pipeline-ffi/Cargo.lock', 'vendor/composer/installed.php'];
        $library = $root.'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so');
        $workloads = self::workloads();
        $metadata = [
            'utc' => gmdate(DATE_ATOM), 'head' => trim((string) shell_exec('git rev-parse HEAD')),
            'php' => PHP_VERSION, 'os' => php_uname(), 'seed' => 1802, 'samples' => $samples, 'warmup_pairs' => 64,
            'cpu' => trim((string) shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n machdep.cpu.brand_string' : 'uname -m')),
            'limits' => (string) shell_exec('ulimit -a'), 'memory_limit' => ini_get('memory_limit'),
            'rustc' => trim((string) shell_exec('rustc --version')), 'profile' => 'release opt-level=3 lto=true codegen-units=1',
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'),
            'source_sha256' => array_combine($paths, array_map(static fn (string $path): string|false => hash_file('sha256', $root.'/'.$path), $paths)),
            'library_sha256' => hash_file('sha256', $library), 'inputs_base64_php_serialized' => base64_encode(serialize($workloads)),
            'reproduce' => 'NNTMUX_VERIFICATION_METADATA_FINGERPRINT_JOB_BENCH=1 php -d ffi.enable=true vendor/bin/phpunit tests/Unit/RustVerificationMetadataFingerprintJobParityTest.php',
            'protocol' => 'Isolated Container/config; no application bootstrap, database, or production I/O. Public dispatch including FFI, serialization and allocation; objects created outside timing. Config and toggle outside timer. Fixed seed randomized paired order and fixture selection; 64 warmup pairs and 3000 timed pairs per workload. First observations separate. Parity and confirmed native execution before timing. Nearest-rank p99 and approximate 95% rank interval; qualification requires all four common/uncommon mean/p99 ratios >=1.5.',
        ];
        file_put_contents($destination.'.jsonl', json_encode(['event' => 'start', 'metadata' => $metadata], JSON_THROW_ON_ERROR)."\n");
        $results = [];
        try {
            foreach ($workloads as $label => $groups) {
                foreach ($groups as $workload => $fixtures) {
                    $first = [];
                    foreach ($fixtures as $fixture) {
                        $this->prepare($fixture);
                        $expected = self::invoke($fixture, 'Php');
                        $this->enable(true);
                        self::assertNative($fixture, $expected);
                        foreach ([false, true] as $enabled) {
                            $this->enable($enabled);
                            $start = hrtime(true);
                            $observed = self::invoke($fixture);
                            $first[] = [$enabled, hrtime(true) - $start];
                            self::assertSame($expected, $observed);
                        }
                    }
                    for ($i = 0; $i < 64; $i++) {
                        $fixture = $fixtures[$random->getInt(0, count($fixtures) - 1)];
                        $this->prepare($fixture);
                        foreach ($random->getInt(0, 1) === 0 ? [false, true] : [true, false] as $enabled) {
                            $this->enable($enabled);
                            self::invoke($fixture);
                        }
                    }
                    $raw = [];
                    for ($i = 0; $i < $samples; $i++) {
                        $index = $random->getInt(0, count($fixtures) - 1);
                        $fixture = $fixtures[$index];
                        $this->prepare($fixture);
                        $order = $random->getInt(0, 1);
                        $times = [];
                        $observed = [];
                        foreach ($order === 0 ? [0, 1] : [1, 0] as $which) {
                            $this->enable($which === 1);
                            $start = hrtime(true);
                            $observed[$which] = self::invoke($fixture);
                            $times[$which] = hrtime(true) - $start;
                        }
                        self::assertSame($observed[0], $observed[1]);
                        $raw[] = [$index, $order, $times[0], $times[1]];
                    }
                    $php = array_column($raw, 2);
                    $rust = array_column($raw, 3);
                    sort($php);
                    sort($rust);
                    $rank = (int) ceil($samples * 0.99) - 1;
                    $radius = (int) ceil(1.96 * sqrt($samples * 0.99 * 0.01));
                    $lower = max(0, $rank - $radius);
                    $upper = min($samples - 1, $rank + $radius);
                    $mean = array_sum($php) / array_sum($rust);
                    $p99 = $php[$rank] / $rust[$rank];
                    $results[$label][$workload] = [
                        'mean_speedup' => $mean, 'p99_speedup' => $p99,
                        'php_mean_ns' => array_sum($php) / $samples, 'rust_mean_ns' => array_sum($rust) / $samples,
                        'php_p99_ns' => $php[$rank], 'rust_p99_ns' => $rust[$rank],
                        'p99_speedup_rank_interval' => [$php[$lower] / $rust[$upper], $php[$upper] / $rust[$lower]],
                        'samples' => $samples, 'errors' => [], 'first_observations' => $first, 'raw_fixture_order_php_rust_ns' => $raw,
                    ];
                    file_put_contents($destination.'.jsonl', json_encode(['event' => 'measurement', 'method' => $label, 'workload' => $workload, 'result' => $results[$label][$workload]], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
                    fwrite(STDOUT, sprintf("%s %-28s mean %.3fx p99 %.3fx\n", $workload, $label, $mean, $p99));
                }
            }
            foreach ($results as $label => $groups) {
                $metadata['qualified'][$label] = $groups['common']['mean_speedup'] >= 1.5 && $groups['common']['p99_speedup'] >= 1.5 && $groups['uncommon']['mean_speedup'] >= 1.5 && $groups['uncommon']['p99_speedup'] >= 1.5;
            }
            file_put_contents($destination, json_encode($metadata + ['results' => $results], JSON_THROW_ON_ERROR));
            fwrite(STDOUT, 'Benchmark evidence: '.$destination."\n");
        } catch (\Throwable $failure) {
            file_put_contents($destination.'.jsonl', json_encode(['event' => 'invalid', 'message' => $failure->getMessage(), 'class' => get_class($failure)], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
            throw $failure;
        }
    }
}
