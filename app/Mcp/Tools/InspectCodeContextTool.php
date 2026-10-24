<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HasVersionedStructuredOutput;
use App\Services\Mcp\RepositoryContextCollector;
use App\Support\RustPipelineLibrary;
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

#[Name('inspect-code-context')]
#[Description('Collect bounded NNTmux source evidence, callers, tests, rules, configuration keys, and path history for a path, symbol, or error.')]
#[IsReadOnly]
#[IsIdempotent]
class InspectCodeContextTool extends Tool
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
        return self::$useRustFfi && RustPipelineLibrary::resolveDomain('mcp-inspect-prompt', InspectChangeImpactTool::FFI_CDEF) !== null;
    }

    public function schema(JsonSchema $schema): array
    {
        $maximumResults = max(1, (int) config('nntmux-mcp.limits.max_results', 50));

        return [
            'query' => $schema->string()->min(1)->max(8192)->description('Repository-relative path, PHP symbol, or error text to investigate.')->required(),
            'kind' => $schema->string()->enum(['auto', 'path', 'symbol', 'error'])->default('auto')->description('How the query should be interpreted.'),
            'max_results' => $schema->integer()->min(1)->max($maximumResults)->default(min(20, $maximumResults))->description('Maximum source matches to return.'),
        ];
    }

    public function handle(Request $request, RepositoryContextCollector $collector): ResponseFactory|Response
    {
        $maximumResults = max(1, (int) config('nntmux-mcp.limits.max_results', 50));
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:8192'],
            'kind' => ['sometimes', 'string', 'in:auto,path,symbol,error'],
            'max_results' => ['sometimes', 'integer', 'min:1', 'max:'.$maximumResults],
        ]);

        $input = self::normalizeInspectInput($validated['query'], $validated['kind'] ?? null, $validated['max_results'] ?? null, $maximumResults);

        try {
            $evidence = $collector->inspect($input['query'], $input['kind'], $input['max_results']);
        } catch (InvalidArgumentException $exception) {
            return $this->structuredError($exception->getMessage());
        }

        $payload = [
            'schema_version' => (int) config('nntmux-mcp.schema_version', 1),
            'summary' => self::formatCodeContextSummary(count($evidence['matches'])),
            'evidence' => $evidence,
            'warnings' => $evidence['warnings'],
            'truncated' => $evidence['truncated'],
        ];

        return Response::make(Response::text($payload['summary']))->withStructuredContent($payload);
    }

    /** @return array{query: string, kind: string, max_results: int} */
    public static function normalizeInspectInput(string $query, ?string $kind, ?int $maxResults, int $maximumResults): array
    {
        return (self::$useRustFfi ? self::normalizeInspectInputNative($query, $kind, $maxResults, $maximumResults) : null)
            ?? self::normalizeInspectInputPhp($query, $kind, $maxResults, $maximumResults);
    }

    /** @return array{query: string, kind: string, max_results: int} */
    public static function normalizeInspectInputPhp(string $query, ?string $kind, ?int $maxResults, int $maximumResults): array
    {
        return ['query' => trim($query), 'kind' => $kind ?? 'auto', 'max_results' => $maxResults ?? min(20, $maximumResults)];
    }

    /** @return array{query: string, kind: string, max_results: int}|null */
    public static function normalizeInspectInputNative(string $query, ?string $kind, ?int $maxResults, int $maximumResults): ?array
    {
        $result = InspectChangeImpactTool::transformNative(2, [$query, $kind, $maxResults, $maximumResults]);

        return is_array($result) ? $result : null;
    }

    public static function formatCodeContextSummary(int $matchCount): string
    {
        return (self::$useRustFfi ? self::formatCodeContextSummaryNative($matchCount) : null)
            ?? self::formatCodeContextSummaryPhp($matchCount);
    }

    public static function formatCodeContextSummaryPhp(int $matchCount): string
    {
        return sprintf('Found %d relevant source location(s).', $matchCount);
    }

    public static function formatCodeContextSummaryNative(int $matchCount): ?string
    {
        $result = InspectChangeImpactTool::transformNative(3, [$matchCount]);

        return is_string($result) ? $result : null;
    }
}
