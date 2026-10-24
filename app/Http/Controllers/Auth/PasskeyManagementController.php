<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Passkeys\RelyingPartyIdResolver;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use JsonException;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyRegisterOptionsAction;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;
use Spatie\LaravelPasskeys\Models\Passkey;
use Spatie\LaravelPasskeys\Support\Config;
use Throwable;

final class PasskeyManagementController extends Controller
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_passkey_management_normalize_metadata_nul(
    uint8_t has_id,
    int64_t id,
    uint8_t has_name,
    const char *name_ptr,
    size_t name_len,
    uint8_t has_last_used,
    const char *last_used_ptr,
    size_t last_used_len,
    uint8_t has_created,
    const char *created_ptr,
    size_t created_len,
    int64_t deleted_count,
    int64_t *out_scalars,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

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

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
    }

    /**
     * @return array{ok: bool, options: mixed}
     *
     * @throws JsonException
     */
    public static function decodeRegistrationOptionsPayload(string $optionsJson): array
    {
        if (self::$useRustFfi) {
            return self::decodeRegistrationOptionsPayloadNative($optionsJson);
        }

        return self::decodeRegistrationOptionsPayloadPhp($optionsJson);
    }

    /**
     * @return array{ok: bool, options: mixed}
     *
     * @throws JsonException
     */
    public static function decodeRegistrationOptionsPayloadPhp(string $optionsJson): array
    {
        return [
            'ok' => true,
            'options' => json_decode($optionsJson, true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @return array{ok: bool, options: mixed}
     *
     * @throws JsonException
     */
    public static function decodeRegistrationOptionsPayloadNative(string $optionsJson): array
    {
        $meta = self::buildPasskeyStoreAndDestroyPayloadNative(null, null, null, null, 1);

        return [
            'ok' => $meta['destroy_ok'],
            'options' => json_decode($optionsJson, true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @return array{
     *     store_payload: array{
     *         ok: bool,
     *         passkey: array{
     *             id: int|null,
     *             name: string|null,
     *             last_used_at: string|null,
     *             created_at: string|null
     *         }
     *     },
     *     destroy_ok: bool,
     *     destroy_flash_key: string,
     *     destroy_flash_message: string
     * }
     */
    public static function buildPasskeyStoreAndDestroyPayload(
        ?int $id,
        ?string $name,
        ?string $lastUsedAtIso,
        ?string $createdAtIso,
        int $deletedCount = 0
    ): array {
        if (self::$useRustFfi) {
            return self::buildPasskeyStoreAndDestroyPayloadNative(
                $id,
                $name,
                $lastUsedAtIso,
                $createdAtIso,
                $deletedCount
            );
        }

        return self::buildPasskeyStoreAndDestroyPayloadPhp(
            $id,
            $name,
            $lastUsedAtIso,
            $createdAtIso,
            $deletedCount
        );
    }

    /**
     * @return array{
     *     store_payload: array{
     *         ok: bool,
     *         passkey: array{
     *             id: int|null,
     *             name: string|null,
     *             last_used_at: string|null,
     *             created_at: string|null
     *         }
     *     },
     *     destroy_ok: bool,
     *     destroy_flash_key: string,
     *     destroy_flash_message: string
     * }
     */
    public static function buildPasskeyStoreAndDestroyPayloadPhp(
        ?int $id,
        ?string $name,
        ?string $lastUsedAtIso,
        ?string $createdAtIso,
        int $deletedCount = 0
    ): array {
        $destroyOk = $deletedCount > 0;

        return [
            'store_payload' => [
                'ok' => true,
                'passkey' => [
                    'id' => $id,
                    'name' => $name,
                    'last_used_at' => $lastUsedAtIso,
                    'created_at' => $createdAtIso,
                ],
            ],
            'destroy_ok' => $destroyOk,
            'destroy_flash_key' => $destroyOk ? 'success' : 'error',
            'destroy_flash_message' => $destroyOk ? 'Passkey deleted.' : 'Passkey not found.',
        ];
    }

    /**
     * @return array{
     *     store_payload: array{
     *         ok: bool,
     *         passkey: array{
     *             id: int|null,
     *             name: string|null,
     *             last_used_at: string|null,
     *             created_at: string|null
     *         }
     *     },
     *     destroy_ok: bool,
     *     destroy_flash_key: string,
     *     destroy_flash_message: string
     * }
     */
    public static function buildPasskeyStoreAndDestroyPayloadNative(
        ?int $id,
        ?string $name,
        ?string $lastUsedAtIso,
        ?string $createdAtIso,
        int $deletedCount = 0
    ): array {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::buildPasskeyStoreAndDestroyPayloadPhp(
                $id,
                $name,
                $lastUsedAtIso,
                $createdAtIso,
                $deletedCount
            );
        }

        try {
            $nameStr = $name ?? '';
            $lastUsedStr = $lastUsedAtIso ?? '';
            $createdStr = $createdAtIso ?? '';
            $cap = max(128, strlen($nameStr) + strlen($lastUsedStr) + strlen($createdStr) + 64);
            $outScalars = $ffi->new('int64_t[3]');
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_passkey_management_normalize_metadata_nul(
                $id !== null ? 1 : 0,
                $id ?? 0,
                $name !== null ? 1 : 0,
                $nameStr,
                strlen($nameStr),
                $lastUsedAtIso !== null ? 1 : 0,
                $lastUsedStr,
                strlen($lastUsedStr),
                $createdAtIso !== null ? 1 : 0,
                $createdStr,
                strlen($createdStr),
                $deletedCount,
                $outScalars,
                $outBuf,
                $cap
            );
            $mask = $outScalars[0];
            $outId = $outScalars[1];
            $destroyOkInt = $outScalars[2];
            if (
                ! is_int($written)
                || $written <= 0
                || ! is_int($mask)
                || ! is_int($outId)
                || ! is_int($destroyOkInt)
            ) {
                return self::buildPasskeyStoreAndDestroyPayloadPhp(
                    $id,
                    $name,
                    $lastUsedAtIso,
                    $createdAtIso,
                    $deletedCount
                );
            }

            $parts = explode("\0", FFI::string($outBuf, $written));
            if (count($parts) < 5) {
                return self::buildPasskeyStoreAndDestroyPayloadPhp(
                    $id,
                    $name,
                    $lastUsedAtIso,
                    $createdAtIso,
                    $deletedCount
                );
            }

            return [
                'store_payload' => [
                    'ok' => true,
                    'passkey' => [
                        'id' => ($mask & 1) !== 0 ? $outId : null,
                        'name' => ($mask & 2) !== 0 ? $parts[0] : null,
                        'last_used_at' => ($mask & 4) !== 0 ? $parts[1] : null,
                        'created_at' => ($mask & 8) !== 0 ? $parts[2] : null,
                    ],
                ],
                'destroy_ok' => $destroyOkInt === 1,
                'destroy_flash_key' => $parts[3],
                'destroy_flash_message' => $parts[4],
            ];
        } catch (Throwable) {
            return self::buildPasskeyStoreAndDestroyPayloadPhp(
                $id,
                $name,
                $lastUsedAtIso,
                $createdAtIso,
                $deletedCount
            );
        }
    }

    public function options(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $generatePassKeyOptionsAction = Config::getAction(
            'generate_passkey_register_options',
            GeneratePasskeyRegisterOptionsAction::class
        );

        /** @var User $user */
        $user = Auth::user();
        $options = $generatePassKeyOptionsAction->execute($user);
        $request->session()->put('passkey-registration-options', $options);
        $request->session()->put('passkey-registration-name', $validated['name']);

        return response()->json(self::decodeRegistrationOptionsPayload($options));
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'passkey' => ['required', 'string'],
        ]);

        $storePasskeyAction = Config::getAction('store_passkey', StorePasskeyAction::class);

        try {
            /** @var User $user */
            $user = Auth::user();
            /** @var Passkey $passkey */
            $passkey = $storePasskeyAction->execute(
                $user,
                $validated['passkey'],
                $request->session()->pull('passkey-registration-options'),
                RelyingPartyIdResolver::resolve($request),
                ['name' => $validated['name']]
            );
        } catch (Throwable $exception) {
            Log::error('Passkey registration failed', [
                'user_id' => Auth::id(),
                'host' => $request->getHost(),
                'resolved_rp_id' => RelyingPartyIdResolver::resolve($request),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => __('passkeys::passkeys.error_something_went_wrong_generating_the_passkey'),
            ], 422);
        }

        $passkeyId = $passkey?->id;
        $passkeyName = $passkey?->name;
        $lastUsedAt = $passkey?->last_used_at?->toIso8601String();
        $createdAt = $passkey?->created_at?->toIso8601String();

        $payload = self::buildPasskeyStoreAndDestroyPayload(
            is_numeric($passkeyId) ? (int) $passkeyId : null,
            is_string($passkeyName) ? $passkeyName : null,
            is_string($lastUsedAt) ? $lastUsedAt : null,
            is_string($createdAt) ? $createdAt : null
        );

        return response()->json($payload['store_payload']);
    }

    public function destroy(Request $request, int $passkey): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $deleted = (int) $user->passkeys()->where('id', $passkey)->delete();

        $payload = self::buildPasskeyStoreAndDestroyPayload(null, null, null, null, $deleted);

        if ($request->expectsJson()) {
            return response()->json(['ok' => $payload['destroy_ok']]);
        }

        return back()->with($payload['destroy_flash_key'], $payload['destroy_flash_message']);
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
