<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Data\Api\CategoryData;
use App\Models\Category;
use App\Models\Genre;
use App\Models\RootCategory;
use App\Models\UsenetGroup;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\RegistrationStatusService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

final class ApiCapabilitiesService
{
    /**
     * Rust candidates for the pure helpers below. They stay opt-in: none beat PHP by
     * 1.5x in the paired benchmark (tests/Unit/RustSearchMediaApiCapabilitiesParityTest.php).
     */
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_api_capabilities_registration(int32_t available, int32_t is_open, char *out, size_t cap);
        int32_t nntmux_api_capabilities_limits(int64_t max, int64_t default_limit, int64_t *out);
        ssize_t nntmux_api_capabilities_searching(int32_t version, char *out, size_t cap);
        C;

    /**
     * Rust stays opt-in for the Wave 45.5 supported parameter summary: framing the request keys costs
     * more than PHP's array_intersect_key() (min 0.84/0.87/0.56/0.64x); see
     * tests/Unit/RustApiCapabilitiesCanonicalizerQueryCacheUsageW45ParityTest.php.
     */
    public const bool W455_RUST_FFI_DEFAULT = false;

    /** @var array<string, string> */
    private const array SEARCHING_V1 = [
        'search' => 'q,group,minsize,maxsize,maxage,cat,limit,offset,attrs,extended,del,sort',
        'tv-search' => 'q,vid,tvdbid,traktid,rid,tvmazeid,imdbid,tmdbid,season,ep,cat,minsize,maxsize,maxage,limit,offset,attrs,extended,del,sort',
        'movie-search' => 'q,imdbid,tmdbid,traktid,genre,cat,minsize,maxsize,maxage,limit,offset,attrs,extended,del,sort',
        'audio-search' => 'q,cat,minsize,maxsize,maxage,group,limit,offset,attrs,extended,del,sort',
        'book-search' => 'q,title,author,cat,minsize,maxsize,maxage,group,limit,offset,attrs,extended,del,sort',
        'anime-search' => 'q,anidbid,anilistid,cat,minsize,maxsize,maxage,limit,offset,attrs,extended,del,sort',
    ];

    /** @var array<string, string> */
    private const array SEARCHING_V2 = [
        'search' => 'id,group,minsize,maxage,cat,limit,offset,cursor,sort',
        'tv-search' => 'id,vid,tvdbid,traktid,rid,tvmazeid,imdbid,tmdbid,season,ep,cat,minsize,maxage,limit,offset,cursor,sort',
        'movie-search' => 'id,imdbid,tmdbid,traktid,cat,minsize,maxage,limit,offset,cursor,sort',
        'audio-search' => 'id,cat,minsize,maxage,group,limit,offset,cursor,sort',
        'book-search' => 'id,cat,minsize,maxage,group,limit,offset,cursor,sort',
        'anime-search' => 'id,anidbid,anilistid,cat,minsize,maxage,limit,offset,cursor,sort',
    ];

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?bool $useW455RustFfi = null;

    public function __construct(
        private readonly RegistrationStatusService $registrationStatus,
        private readonly ApiQueryParameters $queryParameters,
    ) {}

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function searchFfi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /** @return array<string, mixed> */
    public function v1(bool $includeCatalogs): array
    {
        $data = Cache::remember('api_v1_server_menu', 600, static fn (): array => [
            'server' => [
                'title' => config('app.name'),
                'strapline' => app(ConfigurationProvider::class)->site()->strapline,
                'email' => config('mail.from.address'),
                'meta' => app(ConfigurationProvider::class)->site()->metaKeywords,
                'url' => url('/'),
                'image' => url('/').'/assets/images/tmux_logo.png',
            ],
            'searching' => self::searching(1),
        ]);

        $data = ['server' => $data['server'], 'limits' => $this->limits()] + $data;
        $status = $this->registrationStatus();
        $data['registration'] = $this->registration($status);
        $data['categories'] = $includeCatalogs ? Category::getForMenu() : null;
        $data['groups'] = $includeCatalogs ? $this->groups() : null;
        $data['genres'] = $includeCatalogs ? $this->genres() : null;

        return $data;
    }

    /** @return array<string, mixed> */
    public function v2(): array
    {
        $data = Cache::remember('api_v2_capabilities_cursor_v2', 600, function (): array {
            return [
                'server' => [
                    'title' => config('app.name'),
                    'strapline' => app(ConfigurationProvider::class)->site()->strapline,
                    'email' => config('mail.from.address'),
                    'url' => url('/'),
                ],
                'searching' => self::searching(2),
                'categories' => Category::getForApi()
                    ->map(static fn (RootCategory $category): array => CategoryData::fromCategory($category)->toArray())
                    ->values()->all(),
                'groups' => $this->groups(),
                'genres' => $this->genres(),
            ];
        });

        $data = ['server' => $data['server'], 'limits' => $this->limits()] + $data;
        $status = $this->registrationStatus();
        $data['registration'] = $this->registration($status);

        return $data;
    }

    /**
     * Read outside the capabilities cache so changed limits are advertised immediately.
     *
     * @return array{max: int, default: int}
     */
    private function limits(): array
    {
        return self::limitsPayload($this->queryParameters->maxLimit(), $this->queryParameters->defaultLimit());
    }

    /** @return array{max: int, default: int} */
    public static function limitsPayload(int $max, int $default): array
    {
        return self::$useRustFfi ? self::limitsPayloadNative($max, $default) : self::limitsPayloadPhp($max, $default);
    }

    /** @return array{max: int, default: int} */
    public static function limitsPayloadPhp(int $max, int $default): array
    {
        return ['max' => $max, 'default' => $default];
    }

    /** @return array{max: int, default: int} */
    public static function limitsPayloadNative(int $max, int $default): array
    {
        $ffi = self::searchFfi();
        if ($ffi === null) {
            return self::limitsPayloadPhp($max, $default);
        }

        $out = $ffi->new('int64_t[2]');
        // @phpstan-ignore method.notFound
        if ($ffi->nntmux_api_capabilities_limits($max, $default, $out) !== 0) {
            return self::limitsPayloadPhp($max, $default);
        }

        return ['max' => (int) $out[0], 'default' => (int) $out[1]];
    }

    /**
     * The search functions advertised by API `$version` (1 or 2).
     *
     * @return array<string, array{available: string, supportedParams: string}>
     */
    public static function searching(int $version): array
    {
        return self::$useRustFfi ? self::searchingNative($version) : self::searchingPhp($version);
    }

    /** @return array<string, array{available: string, supportedParams: string}> */
    public static function searchingPhp(int $version): array
    {
        $functions = match ($version) {
            1 => self::SEARCHING_V1,
            2 => self::SEARCHING_V2,
            default => [],
        };

        return array_map(static fn (string $params): array => ['available' => 'yes', 'supportedParams' => $params], $functions);
    }

    /** @return array<string, array{available: string, supportedParams: string}> */
    public static function searchingNative(int $version): array
    {
        $ffi = self::searchFfi();
        if ($ffi === null) {
            return self::searchingPhp($version);
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 2048, $cap);
        if ($scratch === null) {
            return self::searchingPhp($version);
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_api_capabilities_searching($version, $scratch, $cap);
        if (! \is_int($written) || $written <= 0) {
            return self::searchingPhp($version);
        }

        $functions = [];
        foreach (explode("\n", FFI::string($scratch, $written)) as $line) {
            [$name, $params] = explode("\t", $line, 2) + [1 => ''];
            $functions[$name] = ['available' => 'yes', 'supportedParams' => $params];
        }

        return $functions;
    }

    /** Pass null to restore {@see W455_RUST_FFI_DEFAULT}. */
    public static function useW455RustFfi(?bool $enable = true): void
    {
        self::$useW455RustFfi = $enable;
    }

    public static function usesW455RustFfi(): bool
    {
        return self::$useW455RustFfi ?? self::W455_RUST_FFI_DEFAULT;
    }

    public static function resetW455RustFfi(): void
    {
        self::$useW455RustFfi = null;
    }

    /**
     * Summarizes request inputs against the search functions {@see searchingPhp()} advertises for
     * `$version`: `known_functions` / `unknown_functions` by the string `t` value, `parameters` (every
     * key but `t`), `supported_parameters` listed for the function, `unsupported_parameters`,
     * `fully_supported` requests, `max_parameters` and `distinct_functions`. Non-array requests and
     * requests without a string `t` are invalid.
     *
     * @param  array<array-key, mixed>  $requests
     * @return array{requests: int, invalid: int, known_functions: int, unknown_functions: int, parameters: int, supported_parameters: int, unsupported_parameters: int, fully_supported: int, max_parameters: int, distinct_functions: int}
     */
    public static function summarizeSupportedParameterBatch(int $version, array $requests): array
    {
        return self::usesW455RustFfi() ? self::summarizeSupportedParameterBatchNative($version, $requests) : self::summarizeSupportedParameterBatchPhp($version, $requests);
    }

    /**
     * @param  array<array-key, mixed>  $requests
     * @return array{requests: int, invalid: int, known_functions: int, unknown_functions: int, parameters: int, supported_parameters: int, unsupported_parameters: int, fully_supported: int, max_parameters: int, distinct_functions: int}
     */
    public static function summarizeSupportedParameterBatchPhp(int $version, array $requests): array
    {
        $summary = ['requests' => \count($requests), 'invalid' => 0, 'known_functions' => 0, 'unknown_functions' => 0, 'parameters' => 0, 'supported_parameters' => 0, 'unsupported_parameters' => 0, 'fully_supported' => 0, 'max_parameters' => 0, 'distinct_functions' => 0];
        // `t` is never a supported parameter, so intersecting keys skips it.
        $allowed = array_map(static fn (array $function): array => array_flip(explode(',', $function['supportedParams'])), self::searchingPhp($version));
        $distinct = [];
        foreach ($requests as $request) {
            if (! \is_array($request) || ! \is_string($request['t'] ?? null)) {
                $summary['invalid']++;

                continue;
            }
            $function = $request['t'];
            $distinct[$function] = true;
            $count = \count($request) - 1;
            $summary['parameters'] += $count;
            $summary['max_parameters'] = max($summary['max_parameters'], $count);
            if (! isset($allowed[$function])) {
                $summary['unknown_functions']++;
                $summary['unsupported_parameters'] += $count;

                continue;
            }
            $supported = \count(array_intersect_key($request, $allowed[$function]));
            $summary['known_functions']++;
            $summary['supported_parameters'] += $supported;
            $summary['unsupported_parameters'] += $count - $supported;
            $summary['fully_supported'] += (int) ($supported === $count);
        }
        $summary['distinct_functions'] = \count($distinct);

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $requests
     * @return array{requests: int, invalid: int, known_functions: int, unknown_functions: int, parameters: int, supported_parameters: int, unsupported_parameters: int, fully_supported: int, max_parameters: int, distinct_functions: int}
     */
    public static function summarizeSupportedParameterBatchNative(int $version, array $requests): array
    {
        $parts = [(string) $version];
        $invalid = 0;
        foreach ($requests as $request) {
            if (! \is_array($request) || ! \is_string($request['t'] ?? null)) {
                $invalid++;

                continue;
            }
            // Rust splits the keys on NUL and declines the batch when a key held one.
            $parts[] = $request['t'];
            $parts[] = (string) \count($request);
            $parts[] = implode("\0", array_keys($request));
        }
        $fields = ApiInputCanonicalizer::evaluateW455Native(1, $parts, 8);
        if ($fields === null) {
            return self::summarizeSupportedParameterBatchPhp($version, $requests);
        }

        return ['requests' => \count($requests), 'invalid' => $invalid]
            + array_combine(['known_functions', 'unknown_functions', 'parameters', 'supported_parameters', 'unsupported_parameters', 'fully_supported', 'max_parameters', 'distinct_functions'], $fields);
    }

    /** @return list<array<string, mixed>> */
    private function groups(): array
    {
        if (! Schema::hasTable('usenet_groups')) {
            return [];
        }

        return UsenetGroup::query()->where('active', 1)->orderBy('name')
            ->get(['name', 'description', 'last_updated'])
            ->map(static fn (UsenetGroup $group): array => [
                'name' => $group->name,
                'description' => (string) ($group->description ?? ''),
                'lastupdate' => $group->last_updated ? Carbon::parse($group->last_updated)->toRfc2822String() : '',
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function genres(): array
    {
        if (! Schema::hasTable('genres')) {
            return [];
        }

        return Genre::query()->enabled()->orderBy('title')->get(['id', 'title', 'type'])
            ->map(static fn (Genre $genre): array => [
                'id' => $genre->id,
                'name' => $genre->title,
                'categoryid' => (int) ($genre->type ?? 0),
            ])->values()->all();
    }

    /** @param array{available: bool, is_open: bool} $status
     * @return array{available: string, open: string}
     */
    private function registration(array $status): array
    {
        return self::registrationPayload($status['available'], $status['is_open']);
    }

    /** @return array{available: string, open: string} */
    public static function registrationPayload(bool $available, bool $isOpen): array
    {
        return self::$useRustFfi
            ? self::registrationPayloadNative($available, $isOpen)
            : self::registrationPayloadPhp($available, $isOpen);
    }

    /** @return array{available: string, open: string} */
    public static function registrationPayloadPhp(bool $available, bool $isOpen): array
    {
        return [
            'available' => $available ? 'yes' : 'no',
            'open' => $isOpen ? 'yes' : 'no',
        ];
    }

    /** @return array{available: string, open: string} */
    public static function registrationPayloadNative(bool $available, bool $isOpen): array
    {
        $ffi = self::searchFfi();
        $cap = 0;
        $scratch = $ffi === null ? null : RustPipelineLibrary::obtainScratch($ffi, 16, $cap);
        if ($ffi === null || $scratch === null) {
            return self::registrationPayloadPhp($available, $isOpen);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_api_capabilities_registration((int) $available, (int) $isOpen, $scratch, $cap);
        $pair = \is_int($written) && $written > 0 ? explode("\t", FFI::string($scratch, $written), 2) : [];
        if (\count($pair) !== 2 || ! \in_array($pair[0], ['yes', 'no'], true) || ! \in_array($pair[1], ['yes', 'no'], true)) {
            return self::registrationPayloadPhp($available, $isOpen);
        }

        return ['available' => $pair[0], 'open' => $pair[1]];
    }

    /** @return array{available: bool, is_open: bool} */
    private function registrationStatus(): array
    {
        return Cache::remember('api_registration_status', 15, function (): array {
            $status = $this->registrationStatus->resolve();

            return [
                'available' => $status['available'],
                'is_open' => $status['is_open'],
            ];
        });
    }
}
