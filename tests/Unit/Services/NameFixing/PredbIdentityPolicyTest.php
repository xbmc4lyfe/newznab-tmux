<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Services\Predb\PredbIdentityPolicy;
use PHPUnit\Framework\TestCase;

final class PredbIdentityPolicyTest extends TestCase
{
    /** @var list<string> */
    private array $fixtures = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::assertTrue(class_exists(PredbIdentityPolicy::class), 'Loaded policy provenance is not implemented.');
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            unlink($fixture);
        }
        parent::tearDown();
    }

    public function test_current_loaded_policy_has_a_stable_runtime_bound_fingerprint(): void
    {
        $policy = new PredbIdentityPolicy;
        $fingerprint = $policy->fingerprint();
        self::assertIsString($fingerprint);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $fingerprint);
        self::assertSame($fingerprint, $policy->fingerprint());
    }

    public function test_runtime_encoding_changes_invalidate_the_aggregate(): void
    {
        $encoding = mb_internal_encoding();
        $policy = new PredbIdentityPolicy;
        $before = $policy->fingerprint();
        try {
            mb_internal_encoding($encoding === 'ASCII' ? 'UTF-8' : 'ASCII');
            self::assertNotNull($policy->fingerprint());
            self::assertNotSame($before, $policy->fingerprint());
        } finally {
            mb_internal_encoding($encoding);
        }
        self::assertSame($before, $policy->fingerprint());
    }

    public function test_loaded_definition_is_rejected_after_its_source_is_replaced(): void
    {
        [$class, $path, $source] = $this->definition();
        $policy = new PredbIdentityPolicy;
        self::assertTrue($policy->definitionIsCurrent($class));
        file_put_contents($path, str_replace('return 1;', 'return 2;', $source));
        self::assertSame(1, $class::value());
        self::assertFalse($policy->definitionIsCurrent($class));
    }

    public function test_inherited_marker_does_not_authenticate_an_override(): void
    {
        [$class] = $this->definition();
        $child = 'PolicyChild'.bin2hex(random_bytes(6));
        $path = tempnam(sys_get_temp_dir(), 'predb-policy-child-');
        $this->fixtures[] = $path;
        file_put_contents($path, '<?php namespace Tests\\PolicyFixtures; class '.$child.' extends \\'.$class.' { public static function value(): int { return 2; } }');
        require $path;
        self::assertFalse((new PredbIdentityPolicy)->definitionIsCurrent('Tests\\PolicyFixtures\\'.$child));
    }

    public function test_missing_or_duplicate_source_marker_is_unknown(): void
    {
        [$class, $path, $source] = $this->definition();
        $policy = new PredbIdentityPolicy;
        preg_match("/    public const PREDB_IDENTITY_POLICY = '[0-9a-f]{64}';\\n/", $source, $matches);
        self::assertCount(1, $matches);
        file_put_contents($path, str_replace($matches[0], '', $source));
        self::assertFalse($policy->definitionIsCurrent($class));
        file_put_contents($path, $source.$matches[0]);
        self::assertFalse($policy->definitionIsCurrent($class));
    }

    public function test_missing_definition_cannot_supply_policy_authority(): void
    {
        self::assertFalse((new PredbIdentityPolicy)->definitionIsCurrent('Tests\\PolicyFixtures\\AbsentDefinition'));
    }

    /** @return array{class-string, string, string} */
    private function definition(): array
    {
        $name = 'PolicyDefinition'.bin2hex(random_bytes(6));
        $source = "<?php\nnamespace Tests\\PolicyFixtures;\nclass ".$name."\n{\n    public static function value(): int { return 1; }\n}\n";
        $marker = "    public const PREDB_IDENTITY_POLICY = '".hash('sha256', $source)."';\n";
        $source = preg_replace('/\{\n/', "{\n".$marker, $source, 1);
        $path = tempnam(sys_get_temp_dir(), 'predb-policy-definition-');
        $this->fixtures[] = $path;
        file_put_contents($path, $source);
        require $path;

        return ['Tests\\PolicyFixtures\\'.$name, $path, $source];
    }
}
