<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HasVersionedStructuredOutput;
use App\Services\Mcp\RepositoryContextCollector;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use ReflectionReference;
use Throwable;

#[Name('inspect-change-impact')]
#[Description('Inspect staged and unstaged changes for selected paths and identify affected NNTmux interfaces, dependants, rules, tests, and checks.')]
#[IsReadOnly]
#[IsIdempotent]
class InspectChangeImpactTool extends Tool
{
    use HasVersionedStructuredOutput;

    public const string FFI_CDEF = 'int32_t nntmux_w24_mcp_inspect_prompt(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    // Opt-in only until correctness and end-to-end performance gates pass.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi && RustPipelineLibrary::resolveDomain('mcp-inspect-prompt', InspectChangeImpactTool::FFI_CDEF) !== null;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'paths' => $schema->array()->items($schema->string()->min(1))->min(1)->max(20)->unique()->description('Repository-relative changed paths to inspect.')->required(),
        ];
    }

    public function handle(Request $request, RepositoryContextCollector $collector): ResponseFactory|Response
    {
        $validated = $request->validate([
            'paths' => ['required', 'array', 'min:1', 'max:20'],
            'paths.*' => ['required', 'string', 'distinct'],
        ]);

        try {
            $impact = $collector->changeImpact(array_values($validated['paths']));
        } catch (InvalidArgumentException $exception) {
            return $this->structuredError($exception->getMessage());
        }

        $payload = self::buildChangeImpactPayload($impact, (int) config('nntmux-mcp.schema_version', 1));

        return Response::make(Response::text($payload['summary']))->withStructuredContent($payload);
    }

    /**
     * @internal Shared bounded serialization transport for the MCP pure helpers.
     *
     * @param  array<array-key, mixed>  $args
     */
    public static function transformNative(int $op, array $args): mixed
    {
        $maximum = 67_108_864;
        try {
            $remaining = 32_768;
            if (! self::supportsNativeValue($args, 0, $remaining)) {
                return null;
            }
            $input = serialize($args);
            if (strlen($input) > $maximum) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain('mcp-inspect-prompt', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $capacity = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, min($maximum, max(256, strlen($input) + 256)), $capacity);
            if ($scratch === null) {
                return null;
            }
            $capacity = min($maximum, $capacity);
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w24_mcp_inspect_prompt($op, $input, strlen($input), $scratch, $capacity);
            if (is_int($written) && $written < -1 && -$written <= $maximum && -$written > $capacity) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($scratch === null) {
                    return null;
                }
                $capacity = min($maximum, $capacity);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w24_mcp_inspect_prompt($op, $input, strlen($input), $scratch, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity) {
                return null;
            }

            return unserialize(FFI::string($scratch, $written), ['allowed_classes' => false]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Reject values whose serialization could execute hooks, lose identity or
     * change type. Inspect references before descending, including cycles.
     */
    private static function supportsNativeValue(mixed $value, int $depth, int &$remaining): bool
    {
        if ($depth > 64 || --$remaining < 0) {
            return false;
        }
        if (! is_array($value)) {
            return $value === null || is_scalar($value);
        }
        foreach ($value as $key => $entry) {
            if (--$remaining < 0 || ReflectionReference::fromArrayElement($value, $key) !== null
                || ! self::supportsNativeValue($entry, $depth + 1, $remaining)) {
                return false;
            }
        }

        return true;
    }

    public static function formatChangeImpactSummary(int $pathCount): string
    {
        return (self::$useRustFfi ? self::formatChangeImpactSummaryNative($pathCount) : null)
            ?? self::formatChangeImpactSummaryPhp($pathCount);
    }

    public static function formatChangeImpactSummaryPhp(int $pathCount): string
    {
        return sprintf('Assessed change impact for %d path(s).', $pathCount);
    }

    public static function formatChangeImpactSummaryNative(int $pathCount): ?string
    {
        $result = self::transformNative(0, [$pathCount]);

        return is_string($result) ? $result : null;
    }

    /**
     * @param  array<array-key, mixed>  $impact
     * @return array<string, mixed>
     */
    public static function buildChangeImpactPayload(array $impact, int $schemaVersion): array
    {
        return (self::$useRustFfi ? self::buildChangeImpactPayloadNative($impact, $schemaVersion) : null)
            ?? self::buildChangeImpactPayloadPhp($impact, $schemaVersion);
    }

    /**
     * @param  array<array-key, mixed>  $impact
     * @return array<string, mixed>
     */
    public static function buildChangeImpactPayloadPhp(array $impact, int $schemaVersion): array
    {
        return [
            'schema_version' => $schemaVersion,
            'summary' => self::formatChangeImpactSummaryPhp(count($impact['paths'])),
            'evidence' => $impact,
            'warnings' => $impact['warnings'],
            'truncated' => $impact['truncated'],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $impact
     * @return array<string, mixed>|null
     */
    public static function buildChangeImpactPayloadNative(array $impact, int $schemaVersion): ?array
    {
        $result = self::transformNative(1, [$impact, $schemaVersion]);

        return is_array($result) ? $result : null;
    }
}
