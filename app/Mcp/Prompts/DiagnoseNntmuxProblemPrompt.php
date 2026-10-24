<?php

declare(strict_types=1);

namespace App\Mcp\Prompts;

use App\Mcp\Tools\InspectChangeImpactTool;
use App\Support\RustPipelineLibrary;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('diagnose-nntmux-problem')]
#[Description('Evidence-first workflow from an NNTmux failure to root cause, scoped repair, and regression checks.')]
class DiagnoseNntmuxProblemPrompt extends Prompt
{
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

    /** @return list<Argument> */
    public function arguments(): array
    {
        return [
            new Argument('problem', 'The observed error, failure, or unexpected behavior.', required: true),
            new Argument('scope', 'Optional repository paths or subsystem boundary.'),
        ];
    }

    /** @return list<Response> */
    public function handle(Request $request): array
    {
        $validated = $request->validate([
            'problem' => ['required', 'string', 'max:12000'],
            'scope' => ['nullable', 'string', 'max:2000'],
        ]);

        $messages = self::formatDiagnosePromptMessages($validated['problem'], $validated['scope'] ?? null);

        return [Response::text($messages[0])->asAssistant(), Response::text($messages[1])];
    }

    /** @return list<string> */
    public static function formatDiagnosePromptMessages(string $problem, ?string $scope): array
    {
        return (self::$useRustFfi ? self::formatDiagnosePromptMessagesNative($problem, $scope) : null)
            ?? self::formatDiagnosePromptMessagesPhp($problem, $scope);
    }

    /** @return list<string> */
    public static function formatDiagnosePromptMessagesPhp(string $problem, ?string $scope): array
    {
        return [
            'Act as an evidence-first NNTmux debugger. Inspect the producer, runtime path, schema/configuration, history, and related tests before proposing a change. Identify the narrow root cause, preserve public behavior, and finish with focused failure-mode coverage plus the verification plan. Use runtime probes only when useful, and request NNTP explicitly if it is genuinely required.',
            "Problem:\n{$problem}\n\nScope:\n".($scope ?? 'Infer the narrowest safe scope from evidence.'),
        ];
    }

    /** @return list<string>|null */
    public static function formatDiagnosePromptMessagesNative(string $problem, ?string $scope): ?array
    {
        $result = InspectChangeImpactTool::transformNative(6, [$problem, $scope]);

        return is_array($result) ? $result : null;
    }
}
