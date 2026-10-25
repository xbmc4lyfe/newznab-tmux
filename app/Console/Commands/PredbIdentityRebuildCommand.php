<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Predb\PredbIdentityRebuild;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PredbIdentityRebuildCommand extends Command
{
    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustPredbCheckRebuildSuggestionsImportParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w373_predb_rebuild_validate(
            uint32_t flags,
            const char *action_ptr,
            size_t action_len,
            const char *generation_ptr,
            size_t generation_len,
            const char *chunk_ptr,
            size_t chunk_len,
            char *out_buf,
            size_t out_cap
        );
        ssize_t nntmux_w373_predb_rebuild_failure(
            const char *message_ptr,
            size_t message_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Failure messages that may be shown verbatim; anything else is reported generically. */
    public const array SAFE_FAILURES = ['Unsupported PreDB rebuild page.', 'Unsupported PreDB rebuild generation.', 'PreDB rebuild generation, epoch or loaded policy changed.', 'PreDB rebuild inventory is incomplete.', 'PreDB rebuild inventory changed.', 'PreDB rebuild state is unavailable.', 'Unsupported PreDB schema attestation driver.', 'Unsupported PreDB rebuild traversal metadata driver.', 'PreDB rebuild requires its own supported transaction.', 'PreDB loaded identity policy is unavailable.'];

    public const string GENERIC_FAILURE = 'PreDB identity rebuild failed; no certification was reported.';

    private const array ACTIONS = ['start', 'chunk', 'certify'];

    private const array ERRORS = ['none', 'invalid_action', 'start_generation', 'invalid_generation', 'invalid_chunk', 'custom_chunk'];

    private const array ERROR_MESSAGES = [
        'none' => null,
        'invalid_action' => 'Action must be start, chunk, or certify.',
        'start_generation' => 'Start does not accept a generation.',
        'invalid_generation' => 'A 32-character lowercase hexadecimal generation is required.',
        'invalid_chunk' => 'Chunk must be a canonical integer from 1 to 256.',
        'custom_chunk' => 'A custom chunk applies only to the chunk action.',
    ];

    private const int HAS_ACTION = 1;

    private const int HAS_GENERATION = 2;

    private const int GENERATION_OPTION = 4;

    private const int HAS_CHUNK = 8;

    private const string FIELD_SEPARATOR = "\x1F";

    private static ?bool $rustFfiOverride = null;

    private static FFI|false|null $rustFfiHandle = null;

    protected $signature = 'predb:identity-rebuild {action} {--generation=} {--chunk=128}';

    protected $description = 'Execute one finite PreDB identity rebuild operation';

    public function handle(PredbIdentityRebuild $rebuild): int
    {
        $action = $this->argument('action');
        $generation = $this->option('generation');
        $chunk = $this->option('chunk');
        if (! in_array($action, ['start', 'chunk', 'certify'], true)) {
            return $this->invalid('Action must be start, chunk, or certify.');
        }
        if ($action === 'start' && ($generation !== null || $this->input->hasParameterOption('--generation', true))) {
            return $this->invalid('Start does not accept a generation.');
        }
        if ($action !== 'start' && (! is_string($generation) || preg_match('/\A[0-9a-f]{32}\z/D', $generation) !== 1)) {
            return $this->invalid('A 32-character lowercase hexadecimal generation is required.');
        }
        if (! is_string($chunk) || strlen($chunk) > 3 || preg_match('/\A[1-9][0-9]{0,2}\z/D', $chunk) !== 1 || (int) $chunk > 256) {
            return $this->invalid('Chunk must be a canonical integer from 1 to 256.');
        }
        if ($action !== 'chunk' && $chunk !== '128') {
            return $this->invalid('A custom chunk applies only to the chunk action.');
        }
        try {
            $connection = DB::connection();
            if ($action === 'start') {
                $r = $rebuild->start($connection);
                $result = ['phase' => 'start', ...$r, 'certified' => false];
            } elseif ($action === 'chunk') {
                $r = $rebuild->chunk($connection, $generation, (int) $chunk);
                $result = ['phase' => 'chunk', ...$r, 'certified' => false];
            } else {
                $rebuild->certify($connection, $generation);
                $result = ['phase' => 'certify', 'generation' => $generation, 'certified' => true];
            }

        } catch (Throwable $e) {
            $safe = self::SAFE_FAILURES;
            $this->error(in_array($e->getMessage(), $safe, true) ? $e->getMessage() : 'PreDB identity rebuild failed; no certification was reported.');

            return self::FAILURE;
        }
        try {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            try {
                $this->error('PreDB identity operation committed; result output failed. Inspect state before retrying.');
            } catch (Throwable) {
                // The output channel can remain unavailable after the committed operation.
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function invalid(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }

    public static function useRustFfi(?bool $enabled): void
    {
        self::$rustFfiOverride = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$rustFfiOverride ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRustFfi(): void
    {
        self::$rustFfiOverride = null;
        self::$rustFfiHandle = null;
    }

    /**
     * Validates the command's arguments in the same order as handle(), without touching the
     * database: action, start generation, generation syntax, chunk syntax, then custom chunk.
     *
     * @return array{valid: bool, error: string, message: ?string, action: ?string, chunk: int, requires_generation: bool, certifies: bool}
     */
    public static function validateRebuildArguments(?string $action, ?string $generation, bool $generationOptionPresent, ?string $chunk): array
    {
        return (self::usesRustFfi() ? self::validateRebuildArgumentsNative($action, $generation, $generationOptionPresent, $chunk) : null)
            ?? self::validateRebuildArgumentsPhp($action, $generation, $generationOptionPresent, $chunk);
    }

    /**
     * Maps a rebuild failure to the message handle() reports: safe messages verbatim, otherwise generic.
     *
     * @return array{safe: bool, index: int, message: string}
     */
    public static function classifyRebuildFailure(string $message): array
    {
        return (self::usesRustFfi() ? self::classifyRebuildFailureNative($message) : null) ?? self::classifyRebuildFailurePhp($message);
    }

    /**
     * @return array{valid: bool, error: string, message: ?string, action: ?string, chunk: int, requires_generation: bool, certifies: bool}|null
     */
    private static function validateRebuildArgumentsNative(?string $action, ?string $generation, bool $generationOptionPresent, ?string $chunk): ?array
    {
        $flags = ($action === null ? 0 : self::HAS_ACTION) | ($generation === null ? 0 : self::HAS_GENERATION)
            | ($generationOptionPresent ? self::GENERATION_OPTION : 0) | ($chunk === null ? 0 : self::HAS_CHUNK);
        $action ??= '';
        $generation ??= '';
        $chunk ??= '';
        $fields = self::callNative(
            'nntmux_w373_predb_rebuild_validate',
            [$flags, $action, strlen($action), $generation, strlen($generation), $chunk, strlen($chunk)],
            64,
        );
        if ($fields === null || count($fields) !== 6 || ! in_array($fields[1], ['0', '1', '2', '3', '4', '5'], true)
            || ! in_array($fields[2], ['-1', '0', '1', '2'], true) || ! ctype_digit($fields[3])) {
            return null;
        }
        $error = self::ERRORS[(int) $fields[1]];

        return [
            'valid' => $fields[0] === '1',
            'error' => $error,
            'message' => self::ERROR_MESSAGES[$error],
            'action' => $fields[2] === '-1' ? null : self::ACTIONS[(int) $fields[2]],
            'chunk' => (int) $fields[3],
            'requires_generation' => $fields[4] === '1',
            'certifies' => $fields[5] === '1',
        ];
    }

    /**
     * @return array{valid: bool, error: string, message: ?string, action: ?string, chunk: int, requires_generation: bool, certifies: bool}
     */
    private static function validateRebuildArgumentsPhp(?string $action, ?string $generation, bool $generationOptionPresent, ?string $chunk): array
    {
        $index = $action === null ? false : array_search($action, self::ACTIONS, true);
        $chunkValid = $chunk !== null && strlen($chunk) <= 3 && preg_match('/\A[1-9][0-9]{0,2}\z/D', $chunk) === 1 && (int) $chunk <= 256;
        $error = match (true) {
            $index === false => 'invalid_action',
            $index === 0 && ($generation !== null || $generationOptionPresent) => 'start_generation',
            $index !== 0 && ($generation === null || preg_match('/\A[0-9a-f]{32}\z/D', $generation) !== 1) => 'invalid_generation',
            ! $chunkValid => 'invalid_chunk',
            $index !== 1 && $chunk !== '128' => 'custom_chunk',
            default => 'none',
        };

        return [
            'valid' => $error === 'none',
            'error' => $error,
            'message' => self::ERROR_MESSAGES[$error],
            'action' => $index === false ? null : self::ACTIONS[$index],
            'chunk' => $error === 'none' ? (int) $chunk : 0,
            'requires_generation' => $index === 1 || $index === 2,
            'certifies' => $index === 2,
        ];
    }

    /**
     * @return array{safe: bool, index: int, message: string}|null
     */
    private static function classifyRebuildFailureNative(string $message): ?array
    {
        $fields = self::callNative('nntmux_w373_predb_rebuild_failure', [$message, strlen($message)], 16);
        if ($fields === null || count($fields) !== 2 || ($fields[1] !== '-1' && (! ctype_digit($fields[1]) || ! isset(self::SAFE_FAILURES[(int) $fields[1]])))) {
            return null;
        }
        $index = (int) $fields[1];

        return ['safe' => $fields[0] === '1', 'index' => $index, 'message' => $index >= 0 ? self::SAFE_FAILURES[$index] : self::GENERIC_FAILURE];
    }

    /**
     * @return array{safe: bool, index: int, message: string}
     */
    private static function classifyRebuildFailurePhp(string $message): array
    {
        $index = array_search($message, self::SAFE_FAILURES, true);

        return $index === false
            ? ['safe' => false, 'index' => -1, 'message' => self::GENERIC_FAILURE]
            : ['safe' => true, 'index' => $index, 'message' => self::SAFE_FAILURES[$index]];
    }

    /**
     * @param  list<int|string>  $args  arguments preceding the caller-owned output buffer
     * @return list<string>|null
     */
    private static function callNative(string $symbol, array $args, int $minBytes, int $limit = PHP_INT_MAX): ?array
    {
        $ffi = self::rustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written), $limit);
        } catch (Throwable) {
            return null;
        }
    }

    private static function rustFfi(): ?FFI
    {
        if (self::$rustFfiHandle === null) {
            self::$rustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w373', self::FFI_CDEF) ?? false;
        }

        return self::$rustFfiHandle === false ? null : self::$rustFfiHandle;
    }
}
