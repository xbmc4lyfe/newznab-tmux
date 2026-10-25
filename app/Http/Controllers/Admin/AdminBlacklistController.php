<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\BlacklistConstants;
use App\Http\Controllers\BasePageController;
use App\Models\Category;
use App\Services\BlacklistService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class AdminBlacklistController extends BasePageController
{
    /**
     * @throws \Exception
     */
    public function index(): mixed
    {
        $this->setAdminPrefs();
        $svc = new BlacklistService;

        $meta_title = $title = 'Binary Black/White List';

        $binlist = $svc->getBlacklist(false);

        $this->viewData = array_merge($this->viewData, [
            'binlist' => $binlist,
            'title' => $title,
            'meta_title' => $meta_title,
        ]);

        return view('admin.blacklist.index', $this->viewData);
    }

    /**
     * @return RedirectResponse|View|void
     *
     * @throws \Exception
     */
    public function edit(Request $request)
    {
        $this->setAdminPrefs();
        $svc = new BlacklistService;
        $state = self::resolveEditState($this->formAction($request, ['view', 'addtest']), $request->all());
        $error = $state['error'];
        $regex = $state['regex'];
        $title = $state['title'];
        $meta_title = $state['meta_title'];

        if ($state['save'] !== null) {
            if ($state['save'] === 'add') {
                $svc->addBlacklist($request->all());
            } else {
                $svc->updateBlacklist($request->all());
            }

            return redirect()->to('admin/binaryblacklist-list');
        }
        if ($state['load']) {
            $regex = $svc->getBlacklistByID((int) $request->input('id'));
        }

        $this->viewData = array_merge($this->viewData, [
            'error' => $error,
            'regex' => (object) $regex,
            'title' => $title,
            'meta_title' => $meta_title,
        ], self::optionMetadata());

        return view('admin.blacklist.edit', $this->viewData);
    }

    public const string FFI_CDEF = 'int32_t nntmux_w28_admin_regex_group_controllers(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    // Keep PHP until each paired common/p99 screen clears 1.5x (see parity test).
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $wave28Ffi = null;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    /**
     * Shared, dependency-free byte-framed bridge for this controller slice.
     * Unsupported PHP values or any native/decoding error decline to the caller.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<string, mixed>|null
     */
    public static function wave28Native(int $operation, array $input): ?array
    {
        try {
            $wire = self::encodeWave28Node($input);
            self::$wave28Ffi ??= RustPipelineLibrary::resolveDomain('wave28-admin-regex-group-controllers', self::FFI_CDEF, ($path = getenv('NNTMUX_PIPELINE_FFI_LIB')) === false || $path === '' ? null : $path) ?? false;
            if (self::$wave28Ffi === false) {
                return null;
            }
            $ffi = self::$wave28Ffi;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 4096, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w28_admin_regex_group_controllers($operation, $wire, strlen($wire), $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                $required = -$written;
                if ($required <= $capacity || $required > 67_108_864) {
                    return null;
                }
                $buffer = RustPipelineLibrary::obtainScratch($ffi, $required, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w28_admin_regex_group_controllers($operation, $wire, strlen($wire), $buffer, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 67_108_864) {
                return null;
            }
            $output = FFI::string($buffer, $written);
            $offset = 0;
            $result = self::decodeWave28Node($output, $offset);
            if (! is_array($result) || $offset !== strlen($output)) {
                return null;
            }
            foreach (array_keys($result) as $key) {
                if (! is_string($key)) {
                    return null;
                }
            }

            return $result;
        } catch (Throwable) {
            return null;
        }
    }

    private static function encodeWave28Node(mixed $value, int $depth = 0): string
    {
        if ($depth > 64) {
            throw new \LengthException('Wave 28 nesting limit');
        }
        $node = match (true) {
            $value === null => 'n',
            is_bool($value) => $value ? 'b1' : 'b0',
            is_int($value) => 'i'.$value,
            is_float($value) => 'f'.json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            is_string($value) => 's'.$value,
            is_array($value) => 'a'.count($value),
            default => throw new \UnexpectedValueException('Unsupported Wave 28 value'),
        };
        if (strlen($node) > 67_108_860) {
            throw new \LengthException('Wave 28 payload limit');
        }
        $wire = pack('V', strlen($node)).$node;
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $wire .= self::encodeWave28Node($key, $depth + 1).self::encodeWave28Node($item, $depth + 1);
                if (strlen($wire) > 67_108_864) {
                    throw new \LengthException('Wave 28 payload limit');
                }
            }
        }

        return $wire;
    }

    private static function decodeWave28Node(string $wire, int &$offset, int $depth = 0): mixed
    {
        if ($depth > 64 || strlen($wire) - $offset < 4) {
            throw new \UnexpectedValueException('Invalid Wave 28 frame');
        }
        $header = unpack('Vlength', $wire, $offset);
        if ($header === false || ! is_int($header['length']) || $header['length'] < 1 || $header['length'] > strlen($wire) - $offset - 4) {
            throw new \UnexpectedValueException('Invalid Wave 28 frame length');
        }
        $node = substr($wire, $offset + 4, $header['length']);
        $offset += 4 + $header['length'];
        $payload = substr($node, 1);
        switch ($node[0]) {
            case 'n':
                if ($node === 'n') {
                    return null;
                }
                break;
            case 'b':
                if ($node === 'b0' || $node === 'b1') {
                    return $node === 'b1';
                }
                break;
            case 's':
                return $payload;
            case 'i':
                $integer = filter_var($payload, FILTER_VALIDATE_INT);
                if ($integer !== false && (string) $integer === $payload) {
                    return $integer;
                }
                break;
            case 'f':
                if (is_numeric($payload) && is_finite((float) $payload)) {
                    return (float) $payload;
                }
                break;
            case 'a':
                $count = filter_var($payload, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($count === false || (string) $count !== $payload || $count > intdiv(strlen($wire) - $offset, 10)) {
                    break;
                }
                $result = [];
                for ($i = 0; $i < $count; $i++) {
                    $key = self::decodeWave28Node($wire, $offset, $depth + 1);
                    if ((! is_int($key) && ! is_string($key)) || array_key_exists($key, $result)) {
                        throw new \UnexpectedValueException('Invalid Wave 28 key');
                    }
                    $result[$key] = self::decodeWave28Node($wire, $offset, $depth + 1);
                }

                return $result;
        }

        throw new \UnexpectedValueException('Invalid Wave 28 node');
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function resolveEditState(string $action, array $input): array
    {
        return self::usesRustFfi() ? self::resolveEditStateNative($action, $input) : self::resolveEditStatePhp($action, $input);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function resolveEditStatePhp(string $action, array $input): array
    {
        $state = ['error' => '', 'regex' => ['id' => '', 'groupname' => '', 'regex' => '', 'description' => '', 'msgcol' => 1, 'status' => 1, 'optype' => 1], 'title' => 'Binary Black/White list', 'meta_title' => 'Binary Black/White list', 'save' => null, 'load' => false];
        if ($action === 'submit') {
            if (($input['groupname'] ?? null) === '') {
                $state['error'] = 'Group must be a valid usenet group';
            } elseif (($input['regex'] ?? null) === '') {
                $state['error'] = 'Regex cannot be empty';
            } else {
                $state['save'] = empty($input['id']) ? 'add' : 'update';
            }
        } elseif ($action === 'addtest') {
            if (array_key_exists('groupname', $input) && array_key_exists('regex', $input)) {
                // Keep the original union: initialized values win over test input.
                $state['regex'] += ['groupname' => $input['groupname'], 'regex' => $input['regex'], 'ordinal' => 1, 'status' => 1];
            }
        } else {
            $state['load'] = array_key_exists('id', $input);
            $state['title'] = $state['load'] ? 'Binary Black/Whitelist Edit' : 'Binary Black/Whitelist Add';
        }

        return $state;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function resolveEditStateNative(string $action, array $input): array
    {
        return self::wave28Native(0, ['action' => $action, 'input' => $input]) ?? self::resolveEditStatePhp($action, $input);
    }

    /** @return array<string, mixed> */
    public static function optionMetadata(): array
    {
        return self::usesRustFfi() ? self::optionMetadataNative() : self::optionMetadataPhp();
    }

    /** @return array<string, mixed> */
    public static function optionMetadataPhp(): array
    {
        return ['status_ids' => [Category::STATUS_ACTIVE, Category::STATUS_INACTIVE], 'status_names' => ['Yes', 'No'], 'optype_ids' => [1, 2], 'optype_names' => ['Black', 'White'], 'msgcol_ids' => [BlacklistConstants::BLACKLIST_FIELD_SUBJECT, BlacklistConstants::BLACKLIST_FIELD_FROM, BlacklistConstants::BLACKLIST_FIELD_MESSAGEID], 'msgcol_names' => ['Subject', 'Poster', 'MessageId']];
    }

    /** @return array<string, mixed> */
    public static function optionMetadataNative(): array
    {
        return self::wave28Native(1, []) ?? self::optionMetadataPhp();
    }

    public const string W391_FFI_CDEF = <<<'C'
        ssize_t nntmux_w391_admin_regex_category_release_blacklist(uint32_t operation, uint32_t flags, const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    /**
     * Rust is the default for summarizeBlacklistRuleHealth(): the paired benchmark in
     * tests/Unit/RustAdminRegexCategoryGroupReleaseW39ParityTest.php clears 1.5x on every workload.
     */
    public const bool W391_RUST_FFI_DEFAULT = true;

    private const int W391_RUST_MAX_INPUT = 16_777_216;

    private const array W391_RULE_KEYS = ['rules', 'invalid_rows', 'active', 'disabled', 'subject', 'poster', 'message_id', 'unknown_column', 'invalid_regex', 'stale',
        'never_active'];

    private static ?bool $w391UseRustFfi = null;

    private static FFI|false|null $w391Ffi = null;

    /**
     * Audits binary blacklist rule rows (`status`, `msgcol`, `regex`, `last_activity`): active (status 1) vs other rules,
     * the subject/poster/message-id target columns, regexes that are empty or would break BlacklistService's
     * `'/'.$regex.'/i'` wrapping, and last activity at least `$staleDays` old (`never_active` when absent).
     * `$nowUnix` <= 0 uses the current time.
     *
     * @param  array<array-key, mixed>  $rules
     * @return array<string, int>
     */
    public static function summarizeBlacklistRuleHealth(array $rules, int $nowUnix = 0, int $staleDays = 90): array
    {
        $now = $nowUnix > 0 ? $nowUnix : time();

        return (self::usesW391RustFfi() ? self::summarizeBlacklistRuleHealthNative($rules, $now, $staleDays) : null)
            ?? self::summarizeBlacklistRuleHealthPhp($rules, $now, $staleDays);
    }

    /**
     * @param  array<array-key, mixed>  $rules
     * @return array<string, int>
     */
    private static function summarizeBlacklistRuleHealthPhp(array $rules, int $now, int $staleDays): array
    {
        $now = max(0, $now);
        $threshold = $staleDays <= 0 ? 0 : ($staleDays > intdiv(PHP_INT_MAX, 86_400) ? PHP_INT_MAX : $staleDays * 86_400);
        $summary = array_fill_keys(self::W391_RULE_KEYS, 0);
        $summary['rules'] = count($rules);
        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                $summary['invalid_rows']++;

                continue;
            }
            $summary[self::w391Int($rule['status'] ?? null) === 1 ? 'active' : 'disabled']++;
            $summary[match (self::w391Int($rule['msgcol'] ?? null)) {
                BlacklistConstants::BLACKLIST_FIELD_SUBJECT => 'subject',
                BlacklistConstants::BLACKLIST_FIELD_FROM => 'poster',
                BlacklistConstants::BLACKLIST_FIELD_MESSAGEID => 'message_id',
                default => 'unknown_column',
            }]++;
            $regex = $rule['regex'] ?? null;
            if (! is_string($regex) || $regex === '' || self::w391BreaksSlashDelimiter($regex)) {
                $summary['invalid_regex']++;
            }
            $last = self::w391Timestamp($rule['last_activity'] ?? null);
            if ($last === null) {
                $summary['never_active']++;
            } elseif ($now - $last >= $threshold) {
                $summary['stale']++;
            }
        }

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $rules
     * @return array<string, int>|null
     */
    private static function summarizeBlacklistRuleHealthNative(array $rules, int $now, int $staleDays): ?array
    {
        $words = [$now, $staleDays, count($rules)];
        $blob = [];
        foreach ($rules as $rule) {
            if (is_array($rule)) {
                self::w391PackRow($words, $blob, [$rule['status'] ?? null, $rule['msgcol'] ?? null, $rule['regex'] ?? null, $rule['last_activity'] ?? null]);
            } else {
                $words[] = 0;
            }
        }
        $values = self::w391Invoke(self::$w391Ffi, 4, $words, $blob);

        return $values !== null && count($values) === count(self::W391_RULE_KEYS) ? array_combine(self::W391_RULE_KEYS, $values) : null;
    }

    /** An unescaped `/` or a trailing lone backslash would end the `'/'.$regex.'/i'` pattern early. */
    private static function w391BreaksSlashDelimiter(string $regex): bool
    {
        return strpbrk($regex, '/\\') !== false && preg_match('~\A(?:[^/\\\\]++|\\\\.)*+\z~s', $regex) !== 1;
    }

    public static function useW391RustFfi(?bool $enabled): void
    {
        self::$w391UseRustFfi = $enabled;
    }

    public static function usesW391RustFfi(): bool
    {
        return self::$w391UseRustFfi ?? self::W391_RUST_FFI_DEFAULT;
    }

    public static function resetW391RustFfi(): void
    {
        self::$w391UseRustFfi = null;
        self::$w391Ffi = null;
    }

    /**
     * Wave 39.1 integer reading shared by the admin audit helpers: an int, or a string of an optional `-` and
     * 1-18 ASCII digits; anything else (floats, bools, other strings) is null.
     */
    public static function w391Int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $digits = $value !== '' && $value[0] === '-' ? substr($value, 1) : $value;
        $length = strlen($digits);

        return $length >= 1 && $length <= 18 && strspn($digits, '0123456789') === $length ? (int) $value : null;
    }

    /**
     * A non-negative int, or a `YYYY-MM-DD[ HH:MM:SS]` string read as UTC (year 1970+, month 1-12, day 1-31,
     * time fields in range; days past a month's end roll over arithmetically).
     */
    public static function w391Timestamp(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        $length = is_string($value) ? strlen($value) : 0;
        if (! is_string($value) || ($length !== 10 && $length !== 19)) {
            return null;
        }
        $long = $length === 19;
        if ($value[4] !== '-' || $value[7] !== '-' || ($long && ($value[10] !== ' ' || $value[13] !== ':' || $value[16] !== ':'))) {
            return null;
        }
        $digits = substr($value, 0, 4).substr($value, 5, 2).substr($value, 8, 2).($long ? substr($value, 11, 2).substr($value, 14, 2).substr($value, 17, 2) : '000000');
        if (strspn($digits, '0123456789') !== 14) {
            return null;
        }
        [$year, $month, $day, $hour, $minute, $second] = [(int) substr($digits, 0, 4), (int) substr($digits, 4, 2), (int) substr($digits, 6, 2),
            (int) substr($digits, 8, 2), (int) substr($digits, 10, 2), (int) substr($digits, 12, 2)];
        if ($year < 1970 || $month < 1 || $month > 12 || $day < 1 || $day > 31 || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }
        $y = $year - ($month <= 2 ? 1 : 0);
        $era = intdiv($y, 400);
        $yearOfEra = $y - $era * 400;
        $dayOfEra = $yearOfEra * 365 + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100) + intdiv(153 * (($month + 9) % 12) + 2, 5) + $day - 1;

        return ($era * 146_097 + $dayOfEra - 719_468) * 86_400 + $hour * 3_600 + $minute * 60 + $second;
    }

    /**
     * Appends an array row to a Wave 39.1 frame: marker 1, then per value its string length (bytes to the blob),
     * -2 and the int, -3 and a float's 8 bytes, or -1.
     *
     * @param  list<int>  $words
     * @param  list<string>  $blob
     * @param  list<mixed>  $values
     */
    public static function w391PackRow(array &$words, array &$blob, array $values): void
    {
        $words[] = 1;
        foreach ($values as $value) {
            if (is_string($value)) {
                $words[] = strlen($value);
                $blob[] = $value;
            } elseif (is_int($value)) {
                $words[] = -2;
                $words[] = $value;
            } elseif (is_float($value)) {
                $words[] = -3;
                $blob[] = pack('d', $value);
            } else {
                $words[] = -1;
            }
        }
    }

    /**
     * Calls the Wave 39.1 export with a frame, retrying once with a larger scratch buffer when it reports
     * `-required`; `$ffi` is the caller's cached library handle. Returns the decoded ints or null to fall back.
     *
     * @param  list<int>  $words
     * @param  list<string>  $blob
     * @return list<int>|null
     */
    public static function w391Invoke(FFI|false|null &$ffi, int $operation, array $words, array $blob): ?array
    {
        $input = pack('q', count($words)).pack('q*', ...$words).implode('', $blob);
        if (strlen($input) > self::W391_RUST_MAX_INPUT) {
            return null;
        }
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w391', self::W391_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 1024, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w391_admin_regex_category_release_blacklist($operation, 0, $input, strlen($input), $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                if (-$written <= $capacity || -$written > self::W391_RUST_MAX_INPUT) {
                    return null;
                }
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w391_admin_regex_category_release_blacklist($operation, 0, $input, strlen($input), $buffer, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written % 8 !== 0) {
                return null;
            }
            $values = unpack('q*', FFI::string($buffer, $written));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
