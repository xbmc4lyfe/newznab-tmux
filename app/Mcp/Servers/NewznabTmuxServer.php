<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Prompts\DiagnoseNntmuxProblemPrompt;
use App\Mcp\Prompts\ReviewNntmuxChangePrompt;
use App\Mcp\Resources\ProjectInstructionsResource;
use App\Mcp\Resources\ProjectOverviewResource;
use App\Mcp\Tools\InspectChangeImpactTool;
use App\Mcp\Tools\InspectCodeContextTool;
use App\Mcp\Tools\PlanVerificationTool;
use App\Mcp\Tools\ProbeRuntimeTool;
use App\Mcp\Tools\RunVerificationTool;
use App\Support\RustPipelineLibrary;
use FFI;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('newznab-tmux Development Server')]
#[Version('1.0.0')]
#[Instructions('Use this local, source-read-only server to gather NNTmux-specific evidence and verify scoped changes. Read project guidance, inspect relevant code and impact, edit through the client workspace, and run targeted verification. Runtime probes are opt-in; never invoke NNTP implicitly.')]
class NewznabTmuxServer extends Server
{
    private const string FFI_CDEF = 'ptrdiff_t nntmux_w33_passkey_auth_admin_inform_mcp(uint32_t mode, const char *input, size_t input_len, char *out, size_t cap);';

    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustPasskeyAuthAdminInformMcpParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        InspectCodeContextTool::class,
        InspectChangeImpactTool::class,
        PlanVerificationTool::class,
        RunVerificationTool::class,
        ProbeRuntimeTool::class,
    ];

    /**
     * @var array<int, class-string<Server\Resource>>
     */
    protected array $resources = [
        ProjectOverviewResource::class,
        ProjectInstructionsResource::class,
    ];

    /**
     * @var array<int, class-string<Prompt>>
     */
    protected array $prompts = [
        DiagnoseNntmuxProblemPrompt::class,
        ReviewNntmuxChangePrompt::class,
    ];

    /**
     * @return array{tools: int, resources: int, prompts: int, total: int, empty: bool, summary: string}
     */
    public function registeredCapabilitySummary(): array
    {
        return self::summarizeRegisteredCapabilities(count($this->tools), count($this->resources), count($this->prompts));
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Negative counts clamp to zero and the total saturates at PHP_INT_MAX.
     *
     * @return array{tools: int, resources: int, prompts: int, total: int, empty: bool, summary: string}
     */
    public static function summarizeRegisteredCapabilities(int $toolCount, int $resourceCount, int $promptCount): array
    {
        return self::usesRustFfi()
            ? self::summarizeRegisteredCapabilitiesNative($toolCount, $resourceCount, $promptCount)
            : self::summarizeRegisteredCapabilitiesPhp($toolCount, $resourceCount, $promptCount);
    }

    /**
     * @return array{tools: int, resources: int, prompts: int, total: int, empty: bool, summary: string}
     */
    public static function summarizeRegisteredCapabilitiesPhp(int $toolCount, int $resourceCount, int $promptCount): array
    {
        $tools = max(0, $toolCount);
        $resources = max(0, $resourceCount);
        $prompts = max(0, $promptCount);
        $total = 0;
        foreach ([$tools, $resources, $prompts] as $count) {
            $total = $total > PHP_INT_MAX - $count ? PHP_INT_MAX : $total + $count;
        }
        $plural = static fn (int $count, string $noun): string => $count === 1 ? "{$count} {$noun}" : "{$count} {$noun}s";

        return [
            'tools' => $tools,
            'resources' => $resources,
            'prompts' => $prompts,
            'total' => $total,
            'empty' => $total === 0,
            'summary' => $plural($tools, 'tool').', '.$plural($resources, 'resource').', '.$plural($prompts, 'prompt'),
        ];
    }

    /**
     * @return array{tools: int, resources: int, prompts: int, total: int, empty: bool, summary: string}
     */
    public static function summarizeRegisteredCapabilitiesNative(int $toolCount, int $resourceCount, int $promptCount): array
    {
        $result = self::nativePacket(5, [(string) $toolCount, (string) $resourceCount, (string) $promptCount]);
        if ($result === null || count($result) !== 6) {
            return self::summarizeRegisteredCapabilitiesPhp($toolCount, $resourceCount, $promptCount);
        }

        return [
            'tools' => (int) $result[0],
            'resources' => (int) $result[1],
            'prompts' => (int) $result[2],
            'total' => (int) $result[3],
            'empty' => $result[4] === '1',
            'summary' => $result[5],
        ];
    }

    /**
     * Count- and length-prefixed (little-endian u32) packets preserve PHP byte strings.
     *
     * @param  list<string>  $items
     * @return list<string>|null
     */
    private static function nativePacket(int $mode, array $items): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null || count($items) > 100000) {
            return null;
        }

        try {
            $input = pack('V', count($items));
            foreach ($items as $item) {
                $input .= pack('V', strlen($item)).$item;
            }
            if (strlen($input) > 16777216) {
                return null;
            }

            $cap = strlen($input) * 2 + 256;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $out = $ffi->new("char[{$cap}]");
                // @phpstan-ignore method.notFound
                $written = (int) $ffi->nntmux_w33_passkey_auth_admin_inform_mcp($mode, $input, strlen($input), $out, $cap);
                if ($written >= 4 && $written <= $cap) {
                    return self::unpackNativePacket(FFI::string($out, $written));
                }
                if ($written > -4 || -$written <= $cap) {
                    return null;
                }
                $cap = -$written;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private static function unpackNativePacket(string $packet): ?array
    {
        $length = strlen($packet);
        $header = unpack('V', $packet);
        $count = is_array($header) ? $header[1] : null;
        if (! is_int($count) || $count > intdiv($length - 4, 4)) {
            return null;
        }

        $offset = 4;
        $parts = [];
        for ($i = 0; $i < $count; $i++) {
            if ($offset + 4 > $length) {
                return null;
            }
            $prefix = unpack('V', $packet, $offset);
            $size = is_array($prefix) ? $prefix[1] : null;
            $offset += 4;
            if (! is_int($size) || $size > $length - $offset) {
                return null;
            }
            $parts[] = substr($packet, $offset, $size);
            $offset += $size;
        }

        return $offset === $length ? $parts : null;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$ffi = RustPipelineLibrary::resolveDomain(
                'search',
                self::FFI_CDEF,
                is_string($path) && $path !== '' ? $path : null,
            ) ?? false;
        }

        return self::$ffi instanceof FFI ? self::$ffi : null;
    }
}
