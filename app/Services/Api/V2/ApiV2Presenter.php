<?php

declare(strict_types=1);

namespace App\Services\Api\V2;

use App\Data\Api\DetailsData;
use App\Data\Api\ReleaseData;
use App\Models\Release;
use App\Models\User;
use App\Services\Api\V1\ApiV1Presenter;
use App\Services\Api\V1\ReleaseRows;
use App\Support\RustPipelineLibrary;
use FFI\CData;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Throwable;

final class ApiV2Presenter
{
    // JSON_HEX_TAG would turn category separators such as ">" into "\u003E".
    private const JSON_ENCODING_OPTIONS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * The Wave 46.5 RSS date string batch defaults to Rust: every common and uncommon mean and p99 ratio
     * cleared 1.5x (min 18.62/14.00/12.47/6.86x); see
     * tests/Unit/RustApiV1V2PresenterPayloadRowsXmlContextW46ParityTest.php.
     */
    public const bool W465_RUST_FFI_DEFAULT = true;

    /** Bytes of one `DATE_RSS` string with a four-digit year in UTC. */
    private const int W465_RSS_LENGTH = 31;

    private static ?bool $useRustFfi = null;

    private static ?bool $useW465RustFfi = null;

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

    /** Pass null to restore {@see W465_RUST_FFI_DEFAULT}. */
    public static function useW465RustFfi(?bool $enable = true): void
    {
        self::$useW465RustFfi = $enable;
    }

    public static function usesW465RustFfi(): bool
    {
        return self::$useW465RustFfi ?? self::W465_RUST_FFI_DEFAULT;
    }

    public static function resetW465RustFfi(): void
    {
        self::$useW465RustFfi = null;
    }

    /**
     * {@see formatRssDatesPhp()} over a batch of date strings, in order; Carbon exceptions propagate.
     *
     * @param  list<string>  $dates
     * @return list<string>
     */
    public static function formatRssDateBatch(array $dates): array
    {
        return self::usesW465RustFfi() ? self::formatRssDateBatchNative($dates) : self::formatRssDateBatchPhp($dates);
    }

    /**
     * @param  list<string>  $dates
     * @return list<string>
     */
    public static function formatRssDateBatchPhp(array $dates): array
    {
        $formatted = [];
        foreach ($dates as $date) {
            $formatted[] = Carbon::parse($date)->toRssString();
        }

        return $formatted;
    }

    /**
     * Rust formats strict `Y-m-d H:i:s` and `Y-m-d` strings (years 1000-9999, no rollover) in UTC and
     * leaves NUL slots for Carbon; other time zones, strings holding a NUL and an unavailable library
     * use PHP for the whole batch.
     *
     * @param  list<string>  $dates
     * @return list<string>
     */
    public static function formatRssDateBatchNative(array $dates): array
    {
        $count = \count($dates);
        if ($count === 0 || date_default_timezone_get() !== 'UTC') {
            return self::formatRssDateBatchPhp($dates);
        }
        $raw = ApiV1Presenter::evaluateW465Native(5, ApiV1Presenter::w465Joined($dates), $count * self::W465_RSS_LENGTH);
        if ($raw === null) {
            return self::formatRssDateBatchPhp($dates);
        }
        $formatted = str_split($raw, self::W465_RSS_LENGTH);
        if (str_contains($raw, "\0")) {
            foreach ($formatted as $index => $date) {
                if ($date[0] === "\0") {
                    $formatted[$index] = Carbon::parse($dates[$index])->toRssString();
                }
            }
        }

        return $formatted;
    }

    /** C signature of the Wave 60.5 {@see self::presenterPlanBatch()} helper. */
    private const string W60_PRESENTER_PLAN_FFI_CDEF = 'int64_t nntmux_w605_v2_presenter_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 60.5 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 9.67/5.75/8.14/5.84x); see
     * tests/Unit/RustApiCanonicalizerQueryParamsV1BuilderV2PresenterLogQueryW60ParityTest.php.
     */
    public const bool W60_RUST_FFI_DEFAULT = true;

    /**
     * Wave 60.5 routing and FFI cache, kept in function-local statics (no static properties). Without $op it
     * reports the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]`
     * with room for at least $val words, or null without the library.
     */
    private static function w60RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w60_5', self::W60_PRESENTER_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof \FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W60_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW60RustFfi(): bool
    {
        return self::w60RustFfiState() === true;
    }

    /**
     * Runs $callback with presenterPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW60RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w60RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w60RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 60.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w60Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w60RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w605_v2_presenter_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', \FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch API v2 release projection plan over `id\tsize\tadddate\tpostdate\tcategories_id\tnow`. Eight words per
     * record: `[id, size, size_unit, added_ts, posted_ts, age, parent, flags]`. Id and size are trimmed 1-18 digits;
     * the unit counts 1024 divisions up to 4 (B, KB, MB, GB, TB). Dates are strict UTC `Y-m-d[ H:i:s]` (`T` also
     * separates) with years 1000-9999 and a valid calendar date and clock; an empty or invalid posted date falls back
     * to the added one. Now is 1-12 digits and the age is now minus posted when both are positive and now is later.
     * The parent is the 1-9 digit category minus its remainder by 1000. Flags: 1 invalid id, 2 invalid size,
     * 4 invalid added date, 8 invalid posted date, 16 empty posted date, 32 invalid now, 64 invalid category, 128 not
     * exactly six fields.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function presenterPlanBatch(array $records): array
    {
        return self::usesW60RustFfi() ? self::presenterPlanBatchNative($records) : self::presenterPlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function presenterPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $flags = \count($fields) === 6 ? 0 : 128;
            $id = self::w60Digits(trim($fields[0]), 18);
            if ($id === null) {
                $id = 0;
                $flags |= 1;
            }
            $size = self::w60Digits(trim($fields[1] ?? ''), 18);
            if ($size === null) {
                $size = 0;
                $flags |= 2;
            }
            $unit = 0;
            for ($scaled = $size; $scaled >= 1024 && $unit < 4; $unit++) {
                $scaled = intdiv($scaled, 1024);
            }
            $added = self::w60Timestamp(trim($fields[2] ?? ''));
            if ($added === null) {
                $added = 0;
                $flags |= 4;
            }
            $text = trim($fields[3] ?? '');
            if ($text === '') {
                $posted = $added;
                $flags |= 16;
            } else {
                $posted = self::w60Timestamp($text);
                if ($posted === null) {
                    $posted = $added;
                    $flags |= 8;
                }
            }
            $now = self::w60Digits(trim($fields[5] ?? ''), 12);
            if ($now === null) {
                $now = 0;
                $flags |= 32;
            }
            $age = $now > 0 && $posted > 0 && $now > $posted ? $now - $posted : 0;
            $category = self::w60Digits(trim($fields[4] ?? ''), 9);
            if ($category === null) {
                $parent = 0;
                $flags |= 64;
            } else {
                $parent = $category - $category % 1000;
            }
            array_push($out, $id, $size, $unit, $added, $posted, $age, $parent, $flags);
        }

        return $out;
    }

    /** Strict UTC `Y-m-d` or `Y-m-d H:i:s` (`T` also separates), years 1000-9999, as Unix seconds; else null. */
    private static function w60Timestamp(string $text): ?int
    {
        $length = \strlen($text);
        $long = $length === 19 && ($text[10] === ' ' || $text[10] === 'T');
        if (($length !== 10 && ! $long) || $text[4] !== '-' || $text[7] !== '-') {
            return null;
        }
        $date = substr($text, 0, 4).substr($text, 5, 2).substr($text, 8, 2);
        if (! ctype_digit($date)) {
            return null;
        }
        $year = (int) substr($date, 0, 4);
        $month = (int) substr($date, 4, 2);
        $day = (int) substr($date, 6, 2);
        if ($year < 1000 || ! checkdate($month, $day, $year)) {
            return null;
        }
        $seconds = 0;
        if ($long) {
            if ($text[13] !== ':' || $text[16] !== ':') {
                return null;
            }
            $clock = substr($text, 11, 2).substr($text, 14, 2).substr($text, 17, 2);
            if (! ctype_digit($clock)) {
                return null;
            }
            $hour = (int) substr($clock, 0, 2);
            $minute = (int) substr($clock, 2, 2);
            $second = (int) substr($clock, 4, 2);
            if ($hour > 23 || $minute > 59 || $second > 59) {
                return null;
            }
            $seconds = $hour * 3600 + $minute * 60 + $second;
        }
        // Days since 1970-01-01 in the proleptic Gregorian calendar (positive years only).
        $y = $month <= 2 ? $year - 1 : $year;
        $era = intdiv($y, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($month + ($month > 2 ? -3 : 9)) + 2, 5) + $day - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;

        return ($era * 146097 + $doe - 719468) * 86400 + $seconds;
    }

    /** 1-$max ASCII digits as an integer, else null. */
    private static function w60Digits(string $text, int $max): ?int
    {
        return $text !== '' && \strlen($text) <= $max && ctype_digit($text) ? (int) $text : null;
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function presenterPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w60Words($count, implode("\0", $records), 8 * $count) ?? self::presenterPlanBatchPhp($records);
    }
}
