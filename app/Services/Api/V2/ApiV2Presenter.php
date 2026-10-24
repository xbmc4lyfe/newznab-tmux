<?php

declare(strict_types=1);

namespace App\Services\Api\V2;

use App\Data\Api\DetailsData;
use App\Data\Api\ReleaseData;
use App\Models\Release;
use App\Models\User;
use App\Services\Api\V1\ReleaseRows;
use FFI\CData;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

final class ApiV2Presenter
{
    // JSON_HEX_TAG would turn category separators such as ">" into "\u003E".
    private const JSON_ENCODING_OPTIONS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private static ?bool $useRustFfi = null;

    private static ?CData $rssBuf = null;

    public static function setUseRustFfi(?bool $useRust): void
    {
        self::$useRustFfi = $useRust;
    }

    public static function usesRustFfi(): bool
    {
        if (self::$useRustFfi !== null) {
            return self::$useRustFfi;
        }

        return ReleaseData::ffi() !== null;
    }

    /** @param array<string, mixed> $data */
    public function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], self::JSON_ENCODING_OPTIONS);
    }

    /**
     * @param  iterable<int, Release|\stdClass>  $rows
     * @param  array<string, mixed>  $usage
     * @param  array{next_cursor: string|null, has_more: bool}|null  $pagination
     */
    public function search(iterable $rows, User $user, array $usage, ?array $pagination = null): JsonResponse
    {
        // Search projections omit the partial-publication counts; attach them as API v1 does.
        $rows = (new ReleaseRows(is_array($rows) ? $rows : iterator_to_array($rows, false)))->rows();
        $detailsBaseUrl = url('/details').'/';
        $getNzbBaseUrl = url('/getnzb');
        $results = [];
        foreach ($rows as $row) {
            $results[] = self::formatRelease($row, $user, $detailsBaseUrl, $getNzbBaseUrl);
        }

        $payload = array_merge(
            ['Total' => (int) ($rows[0]->_totalrows ?? 0)],
            $usage,
            ['results' => $results],
        );
        if ($pagination !== null) {
            $payload['pagination'] = $pagination;
        }

        return $this->json($payload);
    }

    public function details(Release|\stdClass $release, User $user): JsonResponse
    {
        return $this->json(DetailsData::toArrayFromRelease(
            $release,
            $user,
            url('/details').'/',
            url('/getnzb')
        ));
    }

    /** @return array<string, mixed> */
    public function usage(User $user, object $statistics): array
    {
        if (self::$useRustFfi ?? true) {
            $native = self::usageNative($user, $statistics);
            if ($native !== null) {
                return $native;
            }
        }

        return self::usagePhp($user, $statistics);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function usageNative(User $user, object $statistics): ?array
    {
        $ffi = ReleaseData::ffi();
        if ($ffi === null) {
            return null;
        }

        $apiTimeRaw = $statistics->api_time ?? null;
        $grabTimeRaw = $statistics->grab_time ?? null;
        $hasApi = ! empty($apiTimeRaw);
        $hasGrab = ! empty($grabTimeRaw);

        $apiOldest = '';
        $grabOldest = '';

        if ($hasApi && $hasGrab) {
            $formatted = self::formatRssDatesNative($apiTimeRaw, $grabTimeRaw);
            if ($formatted === null) {
                return null;
            }
            [$apiOldest, $grabOldest] = $formatted;
        } elseif ($hasApi) {
            $formatted = self::formatRssDatesNative($apiTimeRaw);
            if ($formatted === null) {
                return null;
            }
            $apiOldest = $formatted[0];
        } elseif ($hasGrab) {
            $formatted = self::formatRssDatesNative($grabTimeRaw);
            if ($formatted === null) {
                return null;
            }
            $grabOldest = $formatted[0];
        }

        $role = $user->role;

        return [
            'apiCurrent' => (int) ($statistics->api_count ?? 0),
            'apiMax' => $role->apirequests,
            'grabCurrent' => (int) ($statistics->grab_count ?? 0),
            'grabMax' => $role->downloadrequests,
            'apiOldestTime' => $apiOldest,
            'grabOldestTime' => $grabOldest,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function usagePhp(User $user, object $statistics): array
    {
        return [
            'apiCurrent' => (int) ($statistics->api_count ?? 0),
            'apiMax' => $user->role->apirequests,
            'grabCurrent' => (int) ($statistics->grab_count ?? 0),
            'grabMax' => $user->role->downloadrequests,
            'apiOldestTime' => ! empty($statistics->api_time) ? Carbon::parse($statistics->api_time)->toRfc2822String() : '',
            'grabOldestTime' => ! empty($statistics->grab_time) ? Carbon::parse($statistics->grab_time)->toRfc2822String() : '',
        ];
    }

    /**
     * @param  Release|\stdClass|array<string, mixed>  $release
     * @return array<string, mixed>
     */
    public static function formatRelease(
        Release|\stdClass|array $release,
        User $user,
        ?string $detailsBaseUrl = null,
        ?string $getNzbBaseUrl = null
    ): array {
        if (self::$useRustFfi ?? true) {
            $native = self::formatReleaseNative($release, $user, $detailsBaseUrl, $getNzbBaseUrl);
            if ($native !== null) {
                return $native;
            }
        }

        return self::formatReleasePhp($release, $user, $detailsBaseUrl, $getNzbBaseUrl);
    }

    /**
     * @param  Release|\stdClass|array<string, mixed>  $release
     * @return array<string, mixed>|null
     */
    public static function formatReleaseNative(
        Release|\stdClass|array $release,
        User $user,
        ?string $detailsBaseUrl = null,
        ?string $getNzbBaseUrl = null
    ): ?array {
        return ReleaseData::toArrayFromReleaseNative($release, $user, $detailsBaseUrl, $getNzbBaseUrl);
    }

    /**
     * @param  Release|\stdClass|array<string, mixed>  $release
     * @return array<string, mixed>
     */
    public static function formatReleasePhp(
        Release|\stdClass|array $release,
        User $user,
        ?string $detailsBaseUrl = null,
        ?string $getNzbBaseUrl = null
    ): array {
        return ReleaseData::toArrayFromReleasePhp($release, $user, $detailsBaseUrl, $getNzbBaseUrl);
    }

    /**
     * Format 1, 2, or 3 dates (`pubDate`, `usenet_date`, `date_added` / `added`, `usenetdate`, etc.)
     * into RFC-2822 / RSS strings.
     *
     * @return list<string>
     */
    public static function formatRssDates(mixed $d1, mixed $d2 = null, mixed $d3 = null): array
    {
        $argc = func_num_args();
        if (self::$useRustFfi ?? true) {
            $native = match ($argc) {
                1 => self::formatRssDatesNative($d1),
                2 => self::formatRssDatesNative($d1, $d2),
                default => self::formatRssDatesNative($d1, $d2, $d3),
            };
            if ($native !== null) {
                return $native;
            }
        }

        return match ($argc) {
            1 => self::formatRssDatesPhp($d1),
            2 => self::formatRssDatesPhp($d1, $d2),
            default => self::formatRssDatesPhp($d1, $d2, $d3),
        };
    }

    /**
     * @return list<string>|null
     */
    public static function formatRssDatesNative(mixed $d1, mixed $d2 = null, mixed $d3 = null): ?array
    {
        $ffi = ReleaseData::ffi();
        if ($ffi === null) {
            return null;
        }

        $argc = min(max(func_num_args(), 1), 3);
        if (! is_string($d1) || $d1 === '') {
            return match ($argc) {
                1 => self::formatRssDatesPhp($d1),
                2 => self::formatRssDatesPhp($d1, $d2),
                default => self::formatRssDatesPhp($d1, $d2, $d3),
            };
        }
        if ($argc >= 2 && (! is_string($d2) || $d2 === '')) {
            return match ($argc) {
                2 => self::formatRssDatesPhp($d1, $d2),
                default => self::formatRssDatesPhp($d1, $d2, $d3),
            };
        }
        if ($argc === 3 && (! is_string($d3) || $d3 === '')) {
            return self::formatRssDatesPhp($d1, $d2, $d3);
        }

        $tz = date_default_timezone_get();
        $tzIsUtc = ($tz === 'UTC' || $tz === '+00:00' || $tz === 'Z') ? 1 : 0;
        $buf = self::$rssBuf ??= $ffi->new('uint8_t[96]');

        $s2 = $argc >= 2 && is_string($d2) ? $d2 : null;
        $s3 = $argc === 3 && is_string($d3) ? $d3 : null;

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_api_v2_rss_dates_batch(
            $d1,
            strlen($d1),
            $s2,
            $s2 !== null ? strlen($s2) : 0,
            $s3,
            $s3 !== null ? strlen($s3) : 0,
            $argc,
            $tzIsUtc,
            $buf,
            96,
        );

        if ($argc === 1 && $written === 31) {
            return [\FFI::string($buf, 31)];
        }
        if ($argc === 2 && $written === 63) {
            $raw = \FFI::string($buf, 63);

            return [substr($raw, 0, 31), substr($raw, 32, 31)];
        }
        if ($argc === 3 && $written === 95) {
            $raw = \FFI::string($buf, 95);

            return [substr($raw, 0, 31), substr($raw, 32, 31), substr($raw, 64, 31)];
        }

        return match ($argc) {
            1 => self::formatRssDatesPhp($d1),
            2 => self::formatRssDatesPhp($d1, $d2),
            default => self::formatRssDatesPhp($d1, $d2, $d3),
        };
    }

    /**
     * @return list<string>
     */
    public static function formatRssDatesPhp(mixed $d1, mixed $d2 = null, mixed $d3 = null): array
    {
        $argc = min(max(func_num_args(), 1), 3);
        if ($argc === 1) {
            return [Carbon::parse($d1)->toRssString()];
        }
        if ($argc === 2) {
            return [
                Carbon::parse($d1)->toRssString(),
                Carbon::parse($d2)->toRssString(),
            ];
        }

        return [
            Carbon::parse($d1)->toRssString(),
            Carbon::parse($d2)->toRssString(),
            Carbon::parse($d3)->toRssString(),
        ];
    }
}
