<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\CategoryRegex;
use App\Models\CollectionRegex;
use App\Models\Release;
use App\Models\ReleaseNamingRegex;
use App\Models\UsenetGroup;
use App\Support\ReleaseNameDisplay;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Service for managing regex patterns for collections, categories, and release naming.
 */
class RegexService
{
    public bool $useRustFfi = false;

    /**
     * The ID of the Regex input string matched or the generic name
     */
    public mixed $matchedRegex;

    /**
     * Name of the current table we are working on.
     */
    public string $tableName;

    /**
     * Cache of regex and their TTL.
     *
     * @var array<string, mixed>
     */
    protected array $_regexCache = [];

    /**
     * How long a process reuses its copy of a group's regexes before checking the shared cache
     * again, so edits still reach long-running workers within about a minute of the shared copy.
     */
    private const int LOCAL_REGEX_CACHE_SECONDS = 60;

    /** The collection file counter: an opening bracket or space, `current`, a separator, `total`, a closer. */
    private const string FILE_COUNT_PATTERN = '/[[(\s](\d{1,5})(\/|[\s_]of[\s_]|-)(\d{1,5})[])\s$:]/i';

    /**
     * Default category ID
     */
    protected int $_categoriesID = Category::OTHER_MISC;

    /**
     * RegexService constructor.
     *
     * @param  string  $tableName  The table name to work with (collection_regexes, category_regexes, release_naming_regexes)
     */
    public function __construct(string $tableName = '')
    {
        $this->tableName = $tableName;
    }

    /**
     * Add a new regex.
     *
     * @param  array<string, mixed>  $data
     */
    public function addRegex(array $data): bool
    {
        return (bool) DB::insert(
            sprintf(
                'INSERT INTO %s (group_regex, regex, status, description, ordinal%s) VALUES (%s, %s, %d, %s, %d%s)',
                $this->tableName,
                ($this->tableName === 'category_regexes' ? ', categories_id' : ''),
                trim(escapeString($data['group_regex'])),
                trim(escapeString($data['regex'])),
                $data['status'],
                trim(escapeString($data['description'])),
                $data['ordinal'],
                ($this->tableName === 'category_regexes' ? (', '.$data['categories_id']) : '')
            )
        );
    }

    /**
     * Update a regex with new info.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateRegex(array $data): bool
    {
        return (bool) DB::update(
            sprintf(
                'UPDATE %s
                SET group_regex = %s, regex = %s, status = %d, description = %s, ordinal = %d %s
                WHERE id = %d',
                $this->tableName,
                trim(escapeString($data['group_regex'])),
                trim(escapeString($data['regex'])),
                $data['status'],
                trim(escapeString($data['description'])),
                $data['ordinal'],
                ($this->tableName === 'category_regexes' ? (', categories_id = '.$data['categories_id']) : ''),
                $data['id']
            )
        );
    }

    /**
     * Get a single regex using its id.
     *
     * @return array<string, mixed>
     */
    public function getRegexByID(int $id): array
    {
        return (array) Arr::first(DB::select(sprintf('SELECT * FROM %s WHERE id = %d LIMIT 1', $this->tableName, $id)));
    }

    /**
     * Get paginated regex results.
     *
     * @return mixed
     */
    public function getRegex(string $group_regex = '')
    {
        if ($this->tableName === 'collection_regexes') {
            $table = CollectionRegex::class;
        } elseif ($this->tableName === 'category_regexes') {
            $table = CategoryRegex::class;
        } else {
            $table = ReleaseNamingRegex::class;
        }

        $result = $table::query();
        if ($group_regex !== '') {
            $result->where('group_regex', 'like', '%'.$group_regex.'%');
        }
        $result->orderBy('id');

        return $result->paginate(config('nntmux.items_per_page'));
    }

    /**
     * Get the count of regex in the DB.
     *
     * @param  string  $group_regex  Optional, keyword to find a group.
     */
    public function getCount(string $group_regex = ''): int
    {
        $query = DB::select(
            sprintf(
                'SELECT COUNT(id) AS count FROM %s %s',
                $this->tableName,
                $this->_groupQueryString($group_regex)
            )
        );

        return (int) $query[0]->count;
    }

    /**
     * Delete a regex using its id.
     *
     * @throws Throwable
     */
    public function deleteRegex(int $id): void
    {
        DB::transaction(function () use ($id) {
            DB::delete(sprintf('DELETE FROM %s WHERE id = %d', $this->tableName, $id));
        }, 3);
    }

    /**
     * Test a single collection regex for a group name.
     *
     * Requires table per group to be on.
     *
     * @return array<string, mixed>
     *
     * @throws \Exception
     */
    public function testCollectionRegex(string $groupName, string $regex, int $limit): array
    {
        $groupID = UsenetGroup::getIDByName($groupName);

        if (! $groupID) {
            return [];
        }

        $rows = DB::select(
            'SELECT
                    b.name, b.totalparts, b.currentparts, HEX(b.binaryhash) AS binaryhash,
                    c.fromname, HEX(c.collectionhash) AS collectionhash
                FROM binaries b
                INNER JOIN collections c ON c.id = b.collections_id'
        );

        $data = [];
        if (\count($rows) > 0) {
            $limit--;
            $hashes = [];
            foreach ($rows as $row) {
                if (preg_match($regex, $row->name, $hits)) {
                    ksort($hits);
                    $string = $string2 = '';
                    foreach ($hits as $key => $hit) {
                        if (! \is_int($key)) {
                            $string .= $hit;
                            $string2 .= '<br/>'.$key.': '.$hit;
                        }
                    }
                    $newCollectionHash = $this->collectionHash($row->name, $string, $row->fromname, $groupID);
                    $data['New hash: '.$newCollectionHash.$string2][$row->binaryhash] = [
                        'new_collection_hash' => $newCollectionHash,
                        'file_name' => $row->name,
                        'file_total_parts' => $row->totalparts,
                        'file_current_parts' => $row->currentparts,
                        'collection_poster' => $row->fromname,
                        'old_collection_hash' => $row->collectionhash,
                    ];

                    if ($limit > 0) {
                        if (\count($hashes) > $limit) {
                            break;
                        }
                        $hashes[$newCollectionHash] = '';
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Test release naming regex against releases.
     *
     * @return array<string, mixed>
     *
     * @throws \Exception
     */
    public function testReleaseNamingRegex(mixed $groupName, mixed $regex, mixed $displayLimit, mixed $queryLimit): array
    {
        $groupID = UsenetGroup::getIDByName($groupName);

        if (! $groupID) {
            return [];
        }

        $rows = Release::query()->where('groups_id', $groupID)->select(['name', 'searchname', 'id']);
        if ((int) $queryLimit !== 0) {
            $rows->limit($queryLimit);
        }

        $results = $rows->get();

        $data = [];
        if ($results->isNotEmpty()) {
            $limit = 1;
            foreach ($results as $row) {
                $hit = $this->_matchRegex($regex, $row['name']);
                if ($hit) {
                    $data[$row['id']] = [
                        'subject' => $row['name'],
                        'old_name' => $row['searchname'],
                        'new_name' => $hit,
                    ];
                    if ((int) $displayLimit > 0 && $limit++ >= (int) $displayLimit) {
                        break;
                    }
                }
            }
        }

        return $data;
    }

    /**
     * This will try to find regex in the DB for a group and a usenet subject, attempt to match them and return the matches.
     *
     * @throws \Exception
     */
    public function tryRegex(string $subject, string $groupName): string
    {
        $this->matchedRegex = 0;

        $this->_fetchRegex($groupName);

        $returnString = '';
        // If there are no regex, return and try regex in this file.
        if ($this->_regexCache[$groupName]['regex']) {
            foreach ($this->_regexCache[$groupName]['regex'] as $regex) {
                if ($this->tableName === 'category_regexes') {
                    $this->_categoriesID = $regex->categories_id;
                }

                $returnString = $this->_matchRegex($regex->regex, $subject);
                // If this regex found something, break and return, or else continue trying other regex.
                if ($returnString) {
                    $this->matchedRegex = $regex->id;
                    break;
                }
            }
        }

        return $returnString;
    }

    /**
     * Get the regex from the DB, cache them locally for 15 mins.
     * Cache them also in the cache server, as this script might be terminated.
     */
    protected function _fetchRegex(string $groupName): void
    {
        // tryRegex() runs once per header, so reuse this process's copy for a short while
        // instead of reading and unserializing the list from the shared cache every call.
        // time() is far cheaper than building a Carbon instance on this per-header path.
        $now = Carbon::hasTestNow() ? Carbon::now()->getTimestamp() : time();
        if (isset($this->_regexCache[$groupName]['expires']) && $this->_regexCache[$groupName]['expires'] > $now) {
            return;
        }

        // Get all regex from DB which match the current group name. Cache them for 15 minutes. #CACHEDQUERY#
        $sql = sprintf(
            'SELECT r.id, r.regex %s FROM %s r WHERE \'%s\' REGEXP r.group_regex AND r.status = 1 ORDER BY r.ordinal ASC, r.group_regex ASC',
            ($this->tableName === 'category_regexes' ? ', r.categories_id' : ''),
            $this->tableName,
            $groupName
        );

        $regex = Cache::get(md5($sql));
        if ($regex === null) {
            $regex = DB::select($sql);
            Cache::put(md5($sql), $regex, now()->addMinutes(config('nntmux.cache_expiry_long')));
        }
        $this->_regexCache[$groupName] = ['regex' => $regex, 'expires' => $now + self::LOCAL_REGEX_CACHE_SECONDS];
    }

    /**
     * Find matches on a regex taken from the database.
     *
     * Requires at least 1 named captured group.
     *
     * @throws \Exception
     */
    protected function _matchRegex(string $regex, string $subject): string
    {
        $returnString = '';
        if (preg_match($regex, $subject, $hits) && \count($hits) > 0) {
            // Sort the keys, the named key matches will be concatenated in this order.
            ksort($hits);
            foreach ($hits as $key => $value) {
                switch ($this->tableName) {
                    case 'collection_regexes': // Put this at the top since it's the most important for performance.
                    case 'release_naming_regexes':
                        // Ignore non-named capture groups. Only named capture groups are important.
                        if (\is_int($key) || preg_match('#reqid|parts#i', $key)) {
                            continue 2;
                        }
                        $returnString .= $value; // Concatenate the string to return.
                        break;
                    case 'category_regexes':
                        $returnString = (string) $this->_categoriesID; // Regex matched, so return the category ID.
                        break 2;
                }
            }
        }

        return $returnString;
    }

    /**
     * Format part of a query.
     */
    protected function _groupQueryString(string $group_regex): string
    {
        return $this->useRustFfi ? $this->groupQueryStringNative($group_regex) : $this->groupQueryStringPhp($group_regex);
    }

    public function groupQueryStringNative(string $groupRegex): string
    {
        $pattern = ReleaseNameDisplay::transformNative(0, $groupRegex);

        return $pattern === null ? $this->groupQueryStringPhp($groupRegex) : ($pattern === '' ? '' : 'WHERE group_regex LIKE '.escapeString($pattern));
    }

    public function groupQueryStringPhp(string $group_regex): string
    {
        return $group_regex ? ('WHERE group_regex LIKE '.escapeString('%'.$group_regex.'%')) : '';
    }

    public function collectionFileCountNative(string $subject): string
    {
        return ReleaseNameDisplay::transformNative(1, $subject) ?? $this->collectionFileCountPhp($subject);
    }

    public function collectionFileCountPhp(string $subject): string
    {
        return preg_match(self::FILE_COUNT_PATTERN, $subject, $count) ? $count[3] : '0';
    }

    public function collectionHash(string $subject, string $name, string $poster, int $groupId): string
    {
        return $this->useRustFfi ? $this->collectionHashNative($subject, $name, $poster, $groupId) : $this->collectionHashPhp($subject, $name, $poster, $groupId);
    }

    public function collectionHashPhp(string $subject, string $name, string $poster, int $groupId): string
    {
        return sha1($name.$poster.$groupId.$this->collectionFileCountPhp($subject));
    }

    public function collectionHashNative(string $subject, string $name, string $poster, int $groupId): string
    {
        $input = '';
        foreach ([$subject, $name, $poster, (string) $groupId] as $value) {
            $input .= pack('V', strlen($value)).$value;
        }

        return ReleaseNameDisplay::transformNative(2, $input) ?? $this->collectionHashPhp($subject, $name, $poster, $groupId);
    }

    /**
     * Disabled: the 32-row common path is mostly fixed marshaling cost, so five paired runs measured
     * 2.19-2.28x common mean but only 1.40-2.38x common p99 (2.21-2.29x and 1.95-2.72x uncommon mean
     * and p99; see the W46 parity test), below the 1.5x rule in one run.
     */
    public const bool W461_RUST_FFI_DEFAULT = false;

    public const string W461_FFI_CDEF = <<<'C'
        ssize_t nntmux_w461_regex_file_count_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    /** Largest Wave 46.1 input frame passed to Rust. */
    public const int W461_MAX_INPUT = 16777216;

    /** Frame length standing for an entry over the caller's `$oversize` limit, sent without its bytes. */
    public const int W461_OVERSIZE_LENGTH = 0xFFFFFFFE;

    private const array W461_SUMMARY_KEYS = ['total_subjects', 'empty_subject_count', 'counted_count', 'slash_count', 'of_count', 'dash_count', 'single_total_count', 'zero_total_count'];

    private static ?bool $w461UseRustFfi = null;

    private static FFI|false|null $w461Ffi = null;

    /** Null restores the benchmark default. */
    public static function useW461RustFfi(?bool $enable = true): void
    {
        self::$w461UseRustFfi = $enable;
    }

    public static function usesW461RustFfi(): bool
    {
        return self::$w461UseRustFfi ?? self::W461_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle; the useRustFfi property is untouched. */
    public static function resetW461RustFfi(): void
    {
        self::$w461UseRustFfi = null;
        self::$w461Ffi = null;
    }

    /**
     * Wave 46.1 entries: strings and integers as-is, the first non-null `$fields` value of an array row,
     * otherwise ''. Objects are never cast or serialized.
     *
     * @param  array<array-key, mixed>  $rows
     * @param  list<string>  $fields
     * @return list<string>
     */
    public static function w461Entries(array $rows, array $fields): array
    {
        $entries = [];
        foreach ($rows as $item) {
            if (\is_array($item)) {
                $value = null;
                foreach ($fields as $field) {
                    if (isset($item[$field])) {
                        $value = $item[$field];
                        break;
                    }
                }
                $item = $value;
            }
            $entries[] = \is_string($item) || \is_int($item) ? (string) $item : '';
        }

        return $entries;
    }

    /**
     * Calls a Wave 46.1 export with `$prefix` and the w461Entries() of `$rows`, and decodes its `i64le`
     * counts. Entries are framed as `u32le count, u32le lengths, bytes`, assembled by one implode() so the
     * entry bytes are copied once and never scanned in PHP. `$ffi` is the caller's cached handle. Returns
     * null (fall back to PHP) when the frame is too large, the library or symbol is unavailable, Rust
     * declines the batch, or the output fails validation. An export that only counts entries longer than
     * `$oversize` bytes receives W461_OVERSIZE_LENGTH and no bytes for them.
     *
     * @param  array<array-key, mixed>  $rows
     * @param  list<string>  $fields
     * @return array<int, int>|null
     */
    public static function w461Invoke(FFI|false|null &$ffi, string $cdef, string $symbol, array $rows, array $fields, int $keys, string $prefix = '', int $oversize = PHP_INT_MAX): ?array
    {
        // Same extraction as w461Entries(), inlined to keep marshaling to one pass.
        $parts = [''];
        $lengths = [];
        $total = \strlen($prefix);
        foreach ($rows as $item) {
            if (\is_array($item)) {
                $value = null;
                foreach ($fields as $field) {
                    if (isset($item[$field])) {
                        $value = $item[$field];
                        break;
                    }
                }
                $item = $value;
            }
            $entry = \is_string($item) || \is_int($item) ? (string) $item : '';
            $length = \strlen($entry);
            if ($length > $oversize) {
                $lengths[] = self::W461_OVERSIZE_LENGTH;

                continue;
            }
            $parts[] = $entry;
            $lengths[] = $length;
            $total += $length;
        }
        $count = \count($lengths);
        if ($total + 4 * $count + 4 > self::W461_MAX_INPUT) {
            return null;
        }
        // A NUL-separated frame would need a PHP scan for embedded NULs, which binary frames carry.
        $parts[0] = $prefix.pack('V*', $count, ...$lengths);
        $input = implode('', $parts);
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w461', $cdef) ?? false;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, $keys * 8, $capacity);
            if ($out === null) {
                return null;
            }
            $written = $ffi->{$symbol}($input, \strlen($input), $out, $capacity);
            if ($written !== $keys * 8) {
                return null;
            }
            $values = unpack('q*', FFI::string($out, $written));
            if (! \is_array($values) || $values[1] !== $count || min($values) < 0 || max($values) > $count) {
                return null;
            }

            return $values;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Summarizes subjects with the collectionFileCountPhp() pattern: subjects carrying a file count, by
     * separator (`/`, `of`, `-`), and those whose total is one or zero.
     * Entries come from strings, integers, or the first non-null name, subject array field; other values
     * (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeFileCountBatch(array $rows): array
    {
        return self::usesW461RustFfi() ? self::summarizeFileCountBatchNative($rows) : self::summarizeFileCountBatchPhp($rows);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeFileCountBatchNative(array $rows): array
    {
        $values = self::w461Invoke(self::$w461Ffi, self::W461_FFI_CDEF, 'nntmux_w461_regex_file_count_batch', $rows, ['name', 'subject'], \count(self::W461_SUMMARY_KEYS));

        return $values === null ? self::summarizeFileCountBatchPhp($rows) : array_combine(self::W461_SUMMARY_KEYS, $values);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeFileCountBatchPhp(array $rows): array
    {
        $summary = array_fill_keys(self::W461_SUMMARY_KEYS, 0);
        foreach (self::w461Entries($rows, ['name', 'subject']) as $entry) {
            $summary['total_subjects']++;
            if ($entry === '') {
                $summary['empty_subject_count']++;

                continue;
            }
            if (preg_match(self::FILE_COUNT_PATTERN, $entry, $count) !== 1) {
                continue;
            }
            $summary['counted_count']++;
            $summary[match ($count[2]) {
                '/' => 'slash_count',
                '-' => 'dash_count',
                default => 'of_count',
            }]++;
            $summary['single_total_count'] += (int) ((int) $count[3] === 1);
            $summary['zero_total_count'] += (int) ((int) $count[3] === 0);
        }

        return $summary;
    }

    /**
     * Enabled: the paired benchmark in the W55 parity test measures 2.557x common mean, 2.161x common
     * p99, 1.886x uncommon mean and 1.572x uncommon p99 (per-metric minimum of four runs), above
     * the 1.5x rule.
     */
    private const bool W55_RUST_FFI_DEFAULT = true;

    private const string W55_FFI_CDEF = 'ssize_t nntmux_w551_regex_file_counter_batch(const char *subjects, size_t subjects_len, size_t count, int64_t *out, size_t out_cap);';

    public static function usesW55RustFfi(): bool
    {
        $enabled = self::w55RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W55_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 55 batch routed to Rust or PHP, then restores the routing. */
    public static function withW55RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w55RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w55RustFfiState('set', $previous);
        }
    }

    public const int W55_COUNTER_SHIFT = 20;

    public const int W55_TOTAL_SHIFT = 2;

    public const int W55_SEPARATOR_SLASH = 0;

    public const int W55_SEPARATOR_OF = 1;

    public const int W55_SEPARATOR_DASH = 2;

    /**
     * One file counter word per collection subject: FILE_COUNT_PATTERN's leftmost current and
     * total counts and separator as `current << W55_COUNTER_SHIFT | total << W55_TOTAL_SHIFT |
     * W55_SEPARATOR_*`, or -1 when the subject carries no file counter.
     *
     * @param  array<array-key, string>  $subjects
     * @return list<int>
     */
    public static function collectionFileCounters(array $subjects): array
    {
        return self::usesW55RustFfi() ? self::collectionFileCountersNative($subjects) : self::collectionFileCountersPhp($subjects);
    }

    /**
     * @param  array<array-key, string>  $subjects
     * @return list<int>
     */
    public static function collectionFileCountersPhp(array $subjects): array
    {
        $codes = [];
        foreach ($subjects as $subject) {
            if (preg_match(self::FILE_COUNT_PATTERN, $subject, $count) !== 1) {
                $codes[] = -1;

                continue;
            }
            $codes[] = ((int) $count[1] << self::W55_COUNTER_SHIFT) | ((int) $count[3] << self::W55_TOTAL_SHIFT) | match ($count[2]) {
                '/' => self::W55_SEPARATOR_SLASH,
                '-' => self::W55_SEPARATOR_DASH,
                default => self::W55_SEPARATOR_OF,
            };
        }

        return $codes;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or declines the batch (a row
     * holding a NUL byte).
     *
     * @param  array<array-key, string>  $subjects
     * @return list<int>
     */
    public static function collectionFileCountersNative(array $subjects): array
    {
        $count = \count($subjects);
        /** @var array{0: FFI, 1: CData, 2: int}|null $buffer */
        $buffer = $count === 0 ? null : self::w55RustFfiState('out', $count);
        if ($buffer === null) {
            return self::collectionFileCountersPhp($subjects);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $subjects);
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w551_regex_file_counter_batch($joined, \strlen($joined), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        $codes = $written === $count ? unpack('P*', FFI::string($out, $count * 8)) : false;
        if (! \is_array($codes)) {
            return self::collectionFileCountersPhp($subjects);
        }

        /** @var list<int> */
        return array_values($codes);
    }

    /**
     * Wave 55 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics.
     */
    private static function w55RustFfiState(?string $op = null, mixed $val = null): mixed
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
            case 'out':
                $ffi ??= RustPipelineLibrary::resolveDomain('regex_w55', self::W55_FFI_CDEF) ?? false;
                if (! $ffi instanceof FFI || ! \is_int($val) || $val < 1) {
                    return null;
                }
                if (! $out instanceof CData || $outCap < $val) {
                    $outCap = max(512, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled;
        }
    }
}
