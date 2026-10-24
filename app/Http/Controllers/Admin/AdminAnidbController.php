<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Models\Release;
use App\Services\AnidbService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Stringable;
use Throwable;

class AdminAnidbController extends BasePageController
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_admin_anidb_normalize_search_filter(
        const char *filter_ptr,
        size_t filter_len,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_admin_anidb_resolve_action_and_title(
        const char *context_ptr,
        size_t context_len,
        const char *action_ptr,
        size_t action_len,
        int64_t id,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_admin_anidb_normalize_update_id(
        const char *anidbid_ptr,
        size_t anidbid_len,
        int64_t fallback_id,
        int64_t *out_id
    );
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustAdminAnidbBookMediaCommandsParityTest.php.
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
     * Display a listing of AniDB entries
     */
    public function index(Request $request): View
    {
        $this->setAdminPrefs();

        $anidbService = new AnidbService;
        $resolved = self::resolveActionAndTitle('index');
        $title = $resolved['title'];
        $meta_title = $resolved['meta_title'];

        $animetitle = self::normalizeSearchFilter($this->scalarInput($request, 'animetitle'));
        $anidblist = $anidbService->getAnimeRange($animetitle);

        return view('admin.anidb.index', compact('anidblist', 'animetitle', 'title', 'meta_title'));
    }

    /**
     * Show the form for editing an AniDB entry
     */
    public function edit(Request $request, int $id): View|RedirectResponse
    {
        $this->setAdminPrefs();

        $anidbService = new AnidbService;

        // Set the current action.
        $action = $this->formAction($request);
        $resolved = self::resolveActionAndTitle('edit', $action, $id);

        switch ($resolved['mode']) {
            case 'submit':
                $payload = self::normalizeUpdatePayload($request->all(), $id);
                $anidbService->updateTitle(
                    $payload['anidbid'],
                    $payload['title'],
                    $payload['type'],
                    $payload['startdate'],
                    $payload['enddate'],
                    $payload['related'],
                    $payload['similar'],
                    $payload['creators'],
                    $payload['description'],
                    $payload['rating'],
                    $payload['categories'],
                    $payload['characters'],
                    $payload['epnos'],
                    $payload['airdates'],
                    $payload['episodetitles']
                );

                return redirect()->route('admin.anidb-list')->with('success', 'AniDB entry updated successfully');

            case 'view':
                $title = $resolved['title'];
                $meta_title = $resolved['meta_title'];
                $anime = $anidbService->getAnimeInfo($id);

                return view('admin.anidb.edit', compact('anime', 'title', 'meta_title'));

            default:
                break;
        }

        return redirect()->route('admin.anidb-list');
    }

    /**
     * Remove AniDB ID from releases
     */
    public function destroy(Request $request, int $id): View
    {
        $this->setAdminPrefs();

        $success = false;
        $anidbid = $id;
        $resolved = self::resolveActionAndTitle('destroy', 'view', $id);

        if ($resolved['mode'] === 'remove') {
            $success = Release::removeAnidbIdFromReleases($id);
        }

        $title = $resolved['title'];
        $meta_title = $resolved['meta_title'];

        return view('admin.anidb.remove', compact('success', 'anidbid', 'title', 'meta_title'));
    }

    public static function normalizeSearchFilter(mixed $animeTitle): string
    {
        if (self::$useRustFfi) {
            return self::normalizeSearchFilterNative($animeTitle);
        }

        return self::normalizeSearchFilterPhp($animeTitle);
    }

    public static function normalizeSearchFilterPhp(mixed $animeTitle): string
    {
        if (! is_scalar($animeTitle) && ! $animeTitle instanceof Stringable) {
            return '';
        }

        return (string) $animeTitle;
    }

    public static function normalizeSearchFilterNative(mixed $animeTitle): string
    {
        if (! is_scalar($animeTitle) && ! $animeTitle instanceof Stringable) {
            return '';
        }

        $ffi = self::ffi();
        if ($ffi === null) {
            return self::normalizeSearchFilterPhp($animeTitle);
        }

        $value = (string) $animeTitle;

        try {
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, max(32, strlen($value) + 8), $cap);
            if ($buf === null) {
                return self::normalizeSearchFilterPhp($animeTitle);
            }

            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_admin_anidb_normalize_search_filter',
                $value,
                strlen($value),
                $buf,
                $cap
            );
            if ($rc < -1) {
                $buf = RustPipelineLibrary::obtainScratch($ffi, -$rc, $cap);
                if ($buf === null) {
                    return self::normalizeSearchFilterPhp($animeTitle);
                }
                $rc = (int) self::callFfi(
                    $ffi,
                    'nntmux_admin_anidb_normalize_search_filter',
                    $value,
                    strlen($value),
                    $buf,
                    $cap
                );
            }
            if ($rc < 0) {
                return self::normalizeSearchFilterPhp($animeTitle);
            }

            return $rc > 0 ? FFI::string($buf, $rc) : '';
        } catch (Throwable) {
            return self::normalizeSearchFilterPhp($animeTitle);
        }
    }

    /**
     * @return array{mode: string, title: string, meta_title: string}
     */
    public static function resolveActionAndTitle(
        string $context,
        string $action = 'view',
        int $id = 0,
    ): array {
        if (self::$useRustFfi) {
            return self::resolveActionAndTitleNative($context, $action, $id);
        }

        return self::resolveActionAndTitlePhp($context, $action, $id);
    }

    /**
     * @return array{mode: string, title: string, meta_title: string}
     */
    public static function resolveActionAndTitlePhp(
        string $context,
        string $action = 'view',
        int $id = 0,
    ): array {
        return match ($context) {
            'index' => [
                'mode' => 'index',
                'title' => 'AniDB List',
                'meta_title' => 'AniDB List',
            ],
            'destroy' => [
                'mode' => $id !== 0 ? 'remove' : 'noop',
                'title' => 'Remove AniDB ID from Releases',
                'meta_title' => 'Remove AniDB ID from Releases',
            ],
            default => $action === 'submit'
                ? [
                    'mode' => 'submit',
                    'title' => '',
                    'meta_title' => '',
                ]
                : (! empty($id)
                    ? [
                        'mode' => 'view',
                        'title' => 'AniDB Edit',
                        'meta_title' => 'AniDB Edit',
                    ]
                    : [
                        'mode' => 'redirect',
                        'title' => '',
                        'meta_title' => '',
                    ]),
        };
    }

    /**
     * @return array{mode: string, title: string, meta_title: string}
     */
    public static function resolveActionAndTitleNative(
        string $context,
        string $action = 'view',
        int $id = 0,
    ): array {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::resolveActionAndTitlePhp($context, $action, $id);
        }

        try {
            $lens = $ffi->new('size_t[2]');
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, 64, $cap);
            if ($buf === null) {
                return self::resolveActionAndTitlePhp($context, $action, $id);
            }

            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_admin_anidb_resolve_action_and_title',
                $context,
                strlen($context),
                $action,
                strlen($action),
                $id,
                $lens,
                $buf,
                $cap
            );
            if ($rc < 0) {
                return self::resolveActionAndTitlePhp($context, $action, $id);
            }

            $modeLen = (int) $lens[0];
            $titleLen = (int) $lens[1];
            $raw = $rc > 0 ? FFI::string($buf, $rc) : '';
            $mode = $modeLen > 0 ? substr($raw, 0, $modeLen) : '';
            $title = $titleLen > 0 ? substr($raw, $modeLen, $titleLen) : '';

            return [
                'mode' => $mode,
                'title' => $title,
                'meta_title' => $title,
            ];
        } catch (Throwable) {
            return self::resolveActionAndTitlePhp($context, $action, $id);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     anidbid: int,
     *     title: string,
     *     type: string,
     *     startdate: string,
     *     enddate: string,
     *     related: string,
     *     similar: string,
     *     creators: string,
     *     description: string,
     *     rating: string,
     *     categories: string,
     *     characters: string,
     *     epnos: string,
     *     airdates: string,
     *     episodetitles: string
     * }
     */
    public static function normalizeUpdatePayload(array $input, int $fallbackId = 0): array
    {
        if (self::$useRustFfi) {
            return self::normalizeUpdatePayloadNative($input, $fallbackId);
        }

        return self::normalizeUpdatePayloadPhp($input, $fallbackId);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     anidbid: int,
     *     title: string,
     *     type: string,
     *     startdate: string,
     *     enddate: string,
     *     related: string,
     *     similar: string,
     *     creators: string,
     *     description: string,
     *     rating: string,
     *     categories: string,
     *     characters: string,
     *     epnos: string,
     *     airdates: string,
     *     episodetitles: string
     * }
     */
    public static function normalizeUpdatePayloadPhp(array $input, int $fallbackId = 0): array
    {
        $rawId = $input['anidbid'] ?? null;
        $anidbId = $fallbackId;
        if (is_int($rawId)) {
            $anidbId = $rawId;
        } elseif (is_scalar($rawId) || $rawId instanceof Stringable) {
            $trimmed = trim((string) $rawId);
            if ($trimmed !== '' && preg_match('/^[+-]?\d+$/', $trimmed) === 1) {
                $anidbId = (int) $trimmed;
            }
        }

        $str = static function (string $key) use ($input): string {
            $val = $input[$key] ?? '';

            return (is_scalar($val) || $val instanceof Stringable) ? (string) $val : '';
        };

        return [
            'anidbid' => $anidbId,
            'title' => $str('title'),
            'type' => $str('type'),
            'startdate' => $str('startdate'),
            'enddate' => $str('enddate'),
            'related' => $str('related'),
            'similar' => $str('similar'),
            'creators' => $str('creators'),
            'description' => $str('description'),
            'rating' => $str('rating'),
            'categories' => $str('categories'),
            'characters' => $str('characters'),
            'epnos' => $str('epnos'),
            'airdates' => $str('airdates'),
            'episodetitles' => $str('episodetitles'),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     anidbid: int,
     *     title: string,
     *     type: string,
     *     startdate: string,
     *     enddate: string,
     *     related: string,
     *     similar: string,
     *     creators: string,
     *     description: string,
     *     rating: string,
     *     categories: string,
     *     characters: string,
     *     epnos: string,
     *     airdates: string,
     *     episodetitles: string
     * }
     */
    public static function normalizeUpdatePayloadNative(array $input, int $fallbackId = 0): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::normalizeUpdatePayloadPhp($input, $fallbackId);
        }

        $rawId = $input['anidbid'] ?? null;
        if ($rawId !== null && ! is_scalar($rawId) && ! $rawId instanceof Stringable) {
            return self::normalizeUpdatePayloadPhp($input, $fallbackId);
        }

        $rawIdStr = (string) ($rawId ?? '');

        try {
            $outId = $ffi->new('int64_t[1]');
            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_admin_anidb_normalize_update_id',
                $rawIdStr,
                strlen($rawIdStr),
                $fallbackId,
                $outId
            );
            if ($rc !== 0) {
                return self::normalizeUpdatePayloadPhp($input, $fallbackId);
            }

            $str = static function (string $key) use ($input): string {
                $val = $input[$key] ?? '';

                return (is_scalar($val) || $val instanceof Stringable) ? (string) $val : '';
            };

            return [
                'anidbid' => (int) $outId[0],
                'title' => $str('title'),
                'type' => $str('type'),
                'startdate' => $str('startdate'),
                'enddate' => $str('enddate'),
                'related' => $str('related'),
                'similar' => $str('similar'),
                'creators' => $str('creators'),
                'description' => $str('description'),
                'rating' => $str('rating'),
                'categories' => $str('categories'),
                'characters' => $str('characters'),
                'epnos' => $str('epnos'),
                'airdates' => $str('airdates'),
                'episodetitles' => $str('episodetitles'),
            ];
        } catch (Throwable) {
            return self::normalizeUpdatePayloadPhp($input, $fallbackId);
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

        $envLib = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain(
            'metadata',
            self::FFI_CDEF,
            is_string($envLib) && $envLib !== '' ? $envLib : null,
        );
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
