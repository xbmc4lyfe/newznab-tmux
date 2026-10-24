<?php

declare(strict_types=1);

namespace App\Mcp\Prompts;

use App\Support\RustPipelineLibrary;
use FFI;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;
use Throwable;

#[Name('review-nntmux-change')]
#[Description('Review an NNTmux change for impact, conventions, failure modes, and targeted verification.')]
class ReviewNntmuxChangePrompt extends Prompt
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_mcp_review_change_prompt_messages_nul(
    const char *goal_ptr,
    size_t goal_len,
    uint8_t has_paths,
    const char *paths_ptr,
    size_t paths_len,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    /**
     * Production default stays PHP until paired benchmarks show >= 1.5x Rust speedup.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /** @return list<Argument> */
    public function arguments(): array
    {
        return [
            new Argument('goal', 'The intended behavior and constraints of the change.', required: true),
            new Argument('paths', 'Optional newline- or comma-separated repository-relative paths.'),
        ];
    }

    /** @return list<Response> */
    public function handle(Request $request): array
    {
        $validated = $request->validate([
            'goal' => ['required', 'string', 'max:8000'],
            'paths' => ['nullable', 'string', 'max:4000'],
        ]);

        $messages = self::formatReviewPromptMessages($validated['goal'], $validated['paths'] ?? null);

        return [
            Response::text($messages[0])->asAssistant(),
            Response::text($messages[1]),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function formatReviewPromptMessages(string $goal, ?string $paths = null): array
    {
        if (self::$useRustFfi) {
            return self::formatReviewPromptMessagesNative($goal, $paths);
        }

        return self::formatReviewPromptMessagesPhp($goal, $paths);
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function formatReviewPromptMessagesPhp(string $goal, ?string $paths = null): array
    {
        return [
            'Review the selected NNTmux change as a repository specialist. Inspect scoped diffs and dependants, verify applicable AGENTS.md and .ai/rules guidance, identify behavior and failure-mode risks, check test adequacy, then run only the targeted allowlisted verification that the paths require. Report findings by severity with concrete file evidence, followed by verification results and residual risk.',
            "Change goal:\n{$goal}\n\nChanged paths:\n".($paths ?? 'Derive them from the current scoped diff.'),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function formatReviewPromptMessagesNative(string $goal, ?string $paths = null): array
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::formatReviewPromptMessagesPhp($goal, $paths);
        }

        try {
            $pathsStr = $paths ?? '';
            $cap = max(600, strlen($goal) + strlen($pathsStr) + 512);
            $out = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_mcp_review_change_prompt_messages_nul(
                $goal,
                strlen($goal),
                $paths !== null ? 1 : 0,
                $pathsStr,
                strlen($pathsStr),
                $out,
                $cap
            );
            if (! is_int($written) || $written <= 0) {
                return self::formatReviewPromptMessagesPhp($goal, $paths);
            }

            $parts = explode("\0", FFI::string($out, $written));
            if (count($parts) < 2) {
                return self::formatReviewPromptMessagesPhp($goal, $paths);
            }

            return [$parts[0], $parts[1]];
        } catch (Throwable) {
            return self::formatReviewPromptMessagesPhp($goal, $paths);
        }
    }

    private static function additionalFfi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
