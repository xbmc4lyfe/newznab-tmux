<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Services\Mcp\ProcessRunner;
use App\Support\RustPipelineLibrary;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Name('NNTmux Project Instructions')]
#[Description('Committed AGENTS.md and .ai/rules guidance, bounded for agent context.')]
#[Uri('nntmux://project/instructions')]
#[MimeType('text/markdown')]
class ProjectInstructionsResource extends Resource
{
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

    public function handle(Request $request): Response
    {
        $maximumBytes = max(1, (int) config('nntmux-mcp.limits.max_resource_bytes', 65_536));
        $sections = [];

        foreach ($this->instructionPaths() as $path) {
            $absolutePath = base_path($path);
            if (! is_file($absolutePath)) {
                continue;
            }

            $sections[] = "# {$path}\n\n".(string) file_get_contents($absolutePath);
        }

        return Response::text(self::assembleInstructionDocument(
            $sections,
            $maximumBytes,
            (int) config('nntmux-mcp.schema_version', 1),
        ));
    }

    /**
     * @return list<string>
     */
    private function instructionPaths(): array
    {
        $paths = ['AGENTS.md', '.ai/rules/index.md'];
        $rulePaths = glob(base_path('.ai/rules/*.md')) ?: [];

        foreach ($rulePaths as $rulePath) {
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($rulePath, strlen(base_path()) + 1));
            if ($relativePath !== '.ai/rules/index.md') {
                $paths[] = $relativePath;
            }
        }

        return self::normalizeInstructionPaths($paths);
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function normalizeInstructionPaths(array $paths): array
    {
        return (self::$useRustFfi ? self::normalizeInstructionPathsNative($paths) : null)
            ?? self::normalizeInstructionPathsPhp($paths);
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function normalizeInstructionPathsPhp(array $paths): array
    {
        sort($paths);

        return array_values(array_unique($paths));
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>|null
     */
    public static function normalizeInstructionPathsNative(array $paths): ?array
    {
        foreach ($paths as $path) {
            if (! is_string($path) || is_numeric($path)) {
                return null;
            }
        }
        $result = ProcessRunner::transformNative(5, [$paths]);

        return is_array($result) ? $result : null;
    }

    /**
     * @param  list<string>  $sections
     */
    public static function assembleInstructionDocument(array $sections, int $maximumBytes, int $schemaVersion): string
    {
        return (self::$useRustFfi ? self::assembleInstructionDocumentNative($sections, $maximumBytes, $schemaVersion) : null)
            ?? self::assembleInstructionDocumentPhp($sections, $maximumBytes, $schemaVersion);
    }

    /**
     * @param  list<string>  $sections
     */
    public static function assembleInstructionDocumentPhp(array $sections, int $maximumBytes, int $schemaVersion): string
    {
        $maximumBytes = max(1, $maximumBytes);
        $content = implode("\n\n", $sections);
        $truncated = strlen($content) > $maximumBytes;
        if ($truncated) {
            $content = mb_strcut($content, 0, $maximumBytes)."\n\n[project instructions truncated]";
        }

        return implode("\n", [
            '<!-- schema_version: '.$schemaVersion.' -->',
            '<!-- truncated: '.($truncated ? 'true' : 'false').' -->',
            $content,
        ]);
    }

    /**
     * @param  list<string>  $sections
     */
    public static function assembleInstructionDocumentNative(array $sections, int $maximumBytes, int $schemaVersion): ?string
    {
        $maximumBytes = max(1, $maximumBytes);
        foreach ($sections as $section) {
            if (! is_string($section)) {
                return null;
            }
        }
        $content = implode("\n\n", $sections);
        if (strlen($content) > $maximumBytes && (strcasecmp(mb_internal_encoding(), 'UTF-8') !== 0 || ! mb_check_encoding($content, 'UTF-8'))) {
            return null;
        }
        $result = ProcessRunner::transformNative(6, [$sections, $maximumBytes, $schemaVersion]);

        return is_string($result) ? $result : null;
    }
}
