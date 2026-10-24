<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HasVersionedStructuredOutput;
use App\Services\Mcp\ProcessRunner;
use App\Services\Mcp\RuntimeProbeRunner;
use App\Support\RustPipelineLibrary;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('probe-runtime')]
#[Description('Run only explicitly selected NNTmux service probes and return sanitized health evidence. NNTP is never selected implicitly.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld]
class ProbeRuntimeTool extends Tool
{
    use HasVersionedStructuredOutput;

    // Opt-in only until correctness and end-to-end performance gates pass.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi && RustPipelineLibrary::resolveDomain('mcp-tool-runner', ProcessRunner::FFI_CDEF) !== null;
    }

    private const array PROBES = ['database', 'redis', 'search', 'queue', 'disk', 'nntp'];

    public function schema(JsonSchema $schema): array
    {
        return [
            'probes' => $schema->array()->items($schema->string()->enum(self::PROBES))->min(1)->max(6)->unique()->description('Explicit service probes to run.')->required(),
        ];
    }

    public function handle(Request $request, RuntimeProbeRunner $runner): ResponseFactory
    {
        $validated = $request->validate([
            'probes' => ['required', 'array', 'min:1', 'max:6'],
            'probes.*' => ['required', 'string', 'distinct', 'in:'.implode(',', self::PROBES)],
        ]);
        $results = $runner->run(array_values($validated['probes']));
        $failed = count(array_filter($results, fn (array $result): bool => ! $result['ok']));
        $summary = self::buildProbeToolSummary(count($results), $failed, (int) config('nntmux-mcp.schema_version', 1));
        $payload = [
            'schema_version' => $summary['schema_version'],
            'summary' => $summary['summary'],
            'results' => $results,
            'warnings' => $summary['warnings'],
            'truncated' => $summary['truncated'],
        ];

        return Response::make(Response::text($payload['summary']))->withStructuredContent($payload);
    }

    /**
     * @return array{schema_version: int, summary: string, warnings: list<string>, truncated: bool}
     */
    public static function buildProbeToolSummary(int $total, int $failed, int $schemaVersion): array
    {
        return (self::$useRustFfi ? self::buildProbeToolSummaryNative($total, $failed, $schemaVersion) : null)
            ?? self::buildProbeToolSummaryPhp($total, $failed, $schemaVersion);
    }

    /**
     * @return array{schema_version: int, summary: string, warnings: list<string>, truncated: bool}
     */
    public static function buildProbeToolSummaryPhp(int $total, int $failed, int $schemaVersion): array
    {
        return [
            'schema_version' => $schemaVersion,
            'summary' => sprintf('Completed %d probe(s); %d reported unhealthy.', $total, $failed),
            'warnings' => $failed > 0 ? ['One or more selected runtime services reported unhealthy.'] : [],
            'truncated' => false,
        ];
    }

    /**
     * @return array{schema_version: int, summary: string, warnings: list<string>, truncated: bool}|null
     */
    public static function buildProbeToolSummaryNative(int $total, int $failed, int $schemaVersion): ?array
    {
        $result = ProcessRunner::transformNative(7, [$total, $failed, $schemaVersion]);

        return is_array($result) ? $result : null;
    }
}
