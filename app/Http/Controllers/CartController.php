<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Release;
use App\Models\UsersRelease;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class CartController extends BasePageController
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_cart_parse_guids_csv(
        const char *csv_ptr,
        size_t csv_len,
        size_t *out_count,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_cart_diff_release_ids(
        const int64_t *release_ids_ptr,
        size_t release_len,
        const int64_t *existing_ids_ptr,
        size_t existing_len,
        int64_t *out_ids,
        size_t out_cap
    );
    ssize_t nntmux_cart_format_added_message(
        int64_t added_count,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustCoverCartFailedControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = (bool) $useRustFfi;
    }

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

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * @throws \Exception
     */
    public function index(): mixed
    {
        $results = UsersRelease::getCart(Auth::id())
            ->filter(fn ($item) => $item->release !== null);

        $this->viewData = array_merge($this->viewData, [
            'results' => $results,
            'meta_title' => 'My Download Basket',
            'meta_keywords' => 'search,add,to,cart,download,basket,nzb,description,details',
            'meta_description' => 'Manage Your Download Basket',
        ]);

        return view('cart.index', $this->viewData);
    }

    /**
     * @throws \Exception
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $guids = self::parseCartGuids($this->scalarInput($request, 'id'));

        $releaseIds = Release::query()
            ->whereIn('guid', $guids)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($releaseIds === []) {
            return $request->ajax() || $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'No releases found'], 404)
                : redirect()->to('/cart/index');
        }

        $existingReleaseIds = UsersRelease::query()
            ->where('users_id', $this->userdata->id)
            ->whereIn('releases_id', $releaseIds)
            ->pluck('releases_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $missingReleaseIds = self::diffMissingReleaseIds($releaseIds, $existingReleaseIds);
        $addedCount = UsersRelease::addCartForReleases((int) $this->userdata->id, $missingReleaseIds);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => self::formatCartAddedMessage($addedCount),
                'cartCount' => UsersRelease::where('users_id', $this->userdata->id)->count(),
            ]);
        }

        return redirect()->to('/cart/index');
    }

    /**
     * @param  array<string, mixed>  $id
     *
     * @throws \Exception
     */
    public function destroy(array|string $id): RedirectResponse
    {
        $ids = null;
        if (! empty($id) && ! \is_array($id)) {
            $ids = explode(',', $id);
        } elseif (\is_array($id)) {
            $ids = $id;
        }

        if (! empty($ids) && UsersRelease::delCartByGuid($ids, $this->userdata->id)) {
            return redirect()->to('/cart/index');
        }

        if (! $id) {
            return redirect()->to('/cart/index');
        }

        return redirect()->to('/cart/index');
    }

    /**
     * @return list<string>
     */
    public static function parseCartGuids(string $rawCsv): array
    {
        if (self::$useRustFfi) {
            return self::parseCartGuidsNative($rawCsv);
        }

        return self::parseCartGuidsPhp($rawCsv);
    }

    /**
     * @return list<string>
     */
    public static function parseCartGuidsPhp(string $rawCsv): array
    {
        /** @var list<string> $guids */
        $guids = collect(explode(',', $rawCsv))
            ->map(static fn (string $guid): string => trim($guid))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $guids;
    }

    /**
     * @return list<string>
     */
    public static function parseCartGuidsNative(string $rawCsv): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::parseCartGuidsPhp($rawCsv);
        }

        try {
            $outCount = $ffi->new('size_t[1]');
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, max(64, strlen($rawCsv) + 16), $cap);
            if ($buf === null) {
                return self::parseCartGuidsPhp($rawCsv);
            }

            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_cart_parse_guids_csv',
                $rawCsv,
                strlen($rawCsv),
                $outCount,
                $buf,
                $cap
            );

            if ($rc < -1) {
                $buf = RustPipelineLibrary::obtainScratch($ffi, -$rc, $cap);
                if ($buf === null) {
                    return self::parseCartGuidsPhp($rawCsv);
                }
                $rc = (int) self::callFfi(
                    $ffi,
                    'nntmux_cart_parse_guids_csv',
                    $rawCsv,
                    strlen($rawCsv),
                    $outCount,
                    $buf,
                    $cap
                );
            }

            if ($rc < 0) {
                return self::parseCartGuidsPhp($rawCsv);
            }

            $count = (int) $outCount[0];
            if ($count === 0) {
                return [];
            }

            /** @var list<string> $items */
            $items = explode("\0", FFI::string($buf, $rc));
            if (count($items) !== $count) {
                return self::parseCartGuidsPhp($rawCsv);
            }

            return $items;
        } catch (Throwable) {
            return self::parseCartGuidsPhp($rawCsv);
        }
    }

    /**
     * @param  list<int>  $releaseIds
     * @param  list<int>  $existingReleaseIds
     * @return list<int>
     */
    public static function diffMissingReleaseIds(array $releaseIds, array $existingReleaseIds): array
    {
        if (self::$useRustFfi) {
            return self::diffMissingReleaseIdsNative($releaseIds, $existingReleaseIds);
        }

        return self::diffMissingReleaseIdsPhp($releaseIds, $existingReleaseIds);
    }

    /**
     * @param  list<int>  $releaseIds
     * @param  list<int>  $existingReleaseIds
     * @return list<int>
     */
    public static function diffMissingReleaseIdsPhp(array $releaseIds, array $existingReleaseIds): array
    {
        return array_values(array_diff($releaseIds, $existingReleaseIds));
    }

    /**
     * @param  list<int>  $releaseIds
     * @param  list<int>  $existingReleaseIds
     * @return list<int>
     */
    public static function diffMissingReleaseIdsNative(array $releaseIds, array $existingReleaseIds): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::diffMissingReleaseIdsPhp($releaseIds, $existingReleaseIds);
        }

        try {
            $relLen = count($releaseIds);
            if ($relLen === 0) {
                return [];
            }
            $exLen = count($existingReleaseIds);

            $relC = $ffi->new("int64_t[{$relLen}]");
            foreach ($releaseIds as $idx => $id) {
                $relC[$idx] = $id;
            }

            $exAlloc = max(1, $exLen);
            $exC = $ffi->new("int64_t[{$exAlloc}]");
            foreach ($existingReleaseIds as $idx => $id) {
                $exC[$idx] = $id;
            }

            $outC = $ffi->new("int64_t[{$relLen}]");
            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_cart_diff_release_ids',
                $relC,
                $relLen,
                $exC,
                $exLen,
                $outC,
                $relLen
            );

            if ($rc < 0) {
                return self::diffMissingReleaseIdsPhp($releaseIds, $existingReleaseIds);
            }

            $missing = [];
            for ($i = 0; $i < $rc; $i++) {
                $missing[] = (int) $outC[$i];
            }

            return $missing;
        } catch (Throwable) {
            return self::diffMissingReleaseIdsPhp($releaseIds, $existingReleaseIds);
        }
    }

    public static function formatCartAddedMessage(int $addedCount): string
    {
        if (self::$useRustFfi) {
            return self::formatCartAddedMessageNative($addedCount);
        }

        return self::formatCartAddedMessagePhp($addedCount);
    }

    public static function formatCartAddedMessagePhp(int $addedCount): string
    {
        return $addedCount > 0 ? "{$addedCount} item(s) added to cart" : 'Items already in cart';
    }

    public static function formatCartAddedMessageNative(int $addedCount): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatCartAddedMessagePhp($addedCount);
        }

        try {
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, 64, $cap);
            if ($buf === null) {
                return self::formatCartAddedMessagePhp($addedCount);
            }
            $rc = (int) self::callFfi($ffi, 'nntmux_cart_format_added_message', $addedCount, $buf, $cap);
            if ($rc < -1) {
                $buf = RustPipelineLibrary::obtainScratch($ffi, -$rc, $cap);
                if ($buf === null) {
                    return self::formatCartAddedMessagePhp($addedCount);
                }
                $rc = (int) self::callFfi($ffi, 'nntmux_cart_format_added_message', $addedCount, $buf, $cap);
            }
            if ($rc < 0) {
                return self::formatCartAddedMessagePhp($addedCount);
            }

            return FFI::string($buf, $rc);
        } catch (Throwable) {
            return self::formatCartAddedMessagePhp($addedCount);
        }
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
