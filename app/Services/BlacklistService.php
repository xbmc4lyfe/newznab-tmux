<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BlacklistConstants;
use App\Models\BinaryBlacklist;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\DB;

class BlacklistService
{
    private const string FFI_CDEF = <<<'C'
        int64_t nntmux_blacklist_compile(const char *input, size_t len, char *out, size_t cap);
        int32_t nntmux_blacklist_match(const char *rules, size_t rules_len, const char *subject, size_t subject_len, const char *poster, size_t poster_len, const char *message_id, size_t message_id_len);
        C;

    private FFI|false|null $ffi = null;

    /** @var array<string, string|false> */
    private array $nativeRules = [];

    private ?string $libraryPath = null;

    private bool $useRust = true;

    public function __construct(?string $libraryPath = null, bool $useRust = true)
    {
        $this->libraryPath = $libraryPath;
        $this->useRust = $useRust;
    }

    /**
     * Cache of lists per group name.
     *
     * @var array<string, mixed>
     */
    private array $blackList = [];

    /**
     * @var array<string, mixed>
     */
    private array $whiteList = [];

    /**
     * @var array<string, mixed>
     */
    private array $listsFound = [];

    /**
     * Track blacklist IDs to update last_activity for.
     *
     * @var array<string, mixed>
     */
    private array $idsToUpdate = [];

    /**
     * Check if an article (OVER header) is blacklisted for the given group.
     *
     * @param  array<string, mixed>  $msg
     */
    public function isBlackListed(array $msg, string $groupName): bool
    {
        if (! isset($this->listsFound[$groupName])) {
            $this->retrieveLists($groupName);
        }
        if (! $this->listsFound[$groupName]) {
            return false;
        }

        $blackListed = false;
        $field = [
            BlacklistConstants::BLACKLIST_FIELD_SUBJECT => $msg['Subject'] ?? '',
            BlacklistConstants::BLACKLIST_FIELD_FROM => $msg['From'] ?? '',
            BlacklistConstants::BLACKLIST_FIELD_MESSAGEID => $msg['Message-ID'] ?? '',
        ];

        // Whitelist first: if any whitelist matches, allow; otherwise treat as blacklisted.
        if ($this->whiteList[$groupName]) {
            $blackListed = true;
            foreach ($this->whiteList[$groupName] as $index => $whiteList) {
                // First four rules stay on PHP: early matches do not repay an FFI call.
                if ($index === 4 && $this->useRust && \count($this->whiteList[$groupName]) >= 32
                    && ($this->nativeRules[$groupName.':white'] ?? null) !== false) {
                    $matched = $this->nativeMatch($groupName.':white', $this->whiteList[$groupName], $field);
                    if ($matched !== null) {
                        if ($matched > 0) {
                            $rule = $this->whiteList[$groupName][$matched - 1];
                            $blackListed = false;
                            $this->idsToUpdate[$rule->id] = $rule->id;
                        }
                        break;
                    }
                }
                if (@preg_match('/'.$whiteList->regex.'/i', $field[$whiteList->msgcol])) {
                    $blackListed = false;
                    $this->idsToUpdate[$whiteList->id] = $whiteList->id;
                    break;
                }
            }
        }

        if (! $blackListed && $this->blackList[$groupName]) {
            foreach ($this->blackList[$groupName] as $index => $blackList) {
                if ($index === 4 && $this->useRust && \count($this->blackList[$groupName]) >= 32
                    && ($this->nativeRules[$groupName.':black'] ?? null) !== false) {
                    $matched = $this->nativeMatch($groupName.':black', $this->blackList[$groupName], $field);
                    if ($matched !== null) {
                        if ($matched > 0) {
                            $rule = $this->blackList[$groupName][$matched - 1];
                            $blackListed = true;
                            $this->idsToUpdate[$rule->id] = $rule->id;
                        }
                        break;
                    }
                }
                if (@preg_match('/'.$blackList->regex.'/i', $field[$blackList->msgcol])) {
                    $blackListed = true;
                    $this->idsToUpdate[$blackList->id] = $blackList->id;
                    break;
                }
            }
        }

        return $blackListed;
    }

    /**
     * Fuse large literal rule lists into one FFI call; arbitrary PCRE stays on PHP.
     *
     * @param  list<object>  $rules
     * @param  array<int, mixed>  $fields
     */
    private function nativeMatch(string $key, array $rules, array $fields): ?int
    {
        if ((int) ini_get('pcre.backtrack_limit') < 1000000
            || (int) ini_get('pcre.recursion_limit') < 100000
            || ! \in_array(setlocale(LC_CTYPE, '0'), ['C', 'POSIX', 'C.UTF-8', 'C.utf8'], true)) {
            return null;
        }
        if ($this->ffi === null) {
            $this->ffi = RustPipelineLibrary::resolveDomain('header_parser', self::FFI_CDEF, $this->libraryPath) ?? false;
        }
        if ($this->ffi === false) {
            return null;
        }
        if (! isset($this->nativeRules[$key])) {
            $framed = '';
            foreach ($rules as $rule) {
                if (! \is_string($rule->regex) || ! \in_array($rule->msgcol, [1, 2, 3, '1', '2', '3'], true)) {
                    $this->nativeRules[$key] = false;

                    return null;
                }
                $framed .= chr((int) $rule->msgcol).pack('V', \strlen($rule->regex)).$rule->regex;
            }
            $scratch = RustPipelineLibrary::obtainScratch($this->ffi, \strlen($framed));
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $this->ffi->nntmux_blacklist_compile($framed, \strlen($framed), $scratch, \strlen($framed));
            $this->nativeRules[$key] = \is_int($written) && $written >= 0 ? FFI::string($scratch, $written) : false;
        }
        $plan = $this->nativeRules[$key];
        if ($plan === false || ! \is_string($fields[1]) || ! \is_string($fields[2]) || ! \is_string($fields[3])) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $matched = $this->ffi->nntmux_blacklist_match($plan, \strlen($plan), $fields[1], \strlen($fields[1]), $fields[2], \strlen($fields[2]), $fields[3], \strlen($fields[3]));

        return \is_int($matched) && $matched >= 0 ? $matched : null;
    }

    /**
     * Get and reset collected blacklist IDs that matched during checks.
     *
     * @return list<mixed>
     */
    public function getAndClearIdsToUpdate(): array
    {
        $ids = array_values($this->idsToUpdate);
        $this->idsToUpdate = [];

        return $ids;
    }

    /**
     * Update last_activity timestamp for given blacklist IDs.
     *
     * @param  array<string, mixed>  $ids
     */
    public function updateBlacklistUsage(array $ids): void
    {
        if (empty($ids)) {
            return;
        }
        BinaryBlacklist::query()->whereIn('id', $ids)->update(['last_activity' => now()]);
    }

    /**
     * Query blacklists from DB.
     *
     * @return list<object>
     */
    public function getBlacklist(bool $activeOnly = true, int|string $opType = -1, string $groupName = '', bool $groupRegex = false): array
    {
        $opTypeSql = match ($opType) {
            BlacklistConstants::OPTYPE_BLACKLIST => 'AND bb.optype = '.BlacklistConstants::OPTYPE_BLACKLIST,
            BlacklistConstants::OPTYPE_WHITELIST => 'AND bb.optype = '.BlacklistConstants::OPTYPE_WHITELIST,
            default => '',
        };

        $joinOperator = $groupRegex ? 'REGEXP' : '=';
        $activeSql = $activeOnly ? 'AND bb.status = 1' : '';
        $groupSql = $groupName ? ('AND g.name REGEXP '.escapeString($groupName)) : '';

        $sql = "
                SELECT
                    bb.id, bb.optype, bb.status, bb.description,
                    bb.groupname AS groupname, bb.regex, g.id AS group_id, bb.msgcol,
                    bb.last_activity as last_activity
                FROM binaryblacklist bb
                LEFT OUTER JOIN usenet_groups g ON g.name $joinOperator bb.groupname
                WHERE 1=1 $activeSql $opTypeSql $groupSql
                ORDER BY coalesce(groupname,'zzz')";

        return DB::select($sql);
    }

    public function getBlacklistByID(int $id): mixed
    {
        return BinaryBlacklist::query()->where('id', $id)->first();
    }

    public function deleteBlacklist(int $id): void
    {
        BinaryBlacklist::query()->where('id', $id)->delete();
    }

    /**
     * @param  array<string, mixed>  $blacklistArray
     */
    public function updateBlacklist(array $blacklistArray): void
    {
        BinaryBlacklist::query()->where('id', $blacklistArray['id'])->update(
            [
                'groupname' => $blacklistArray['groupname'] === '' ? 'null' : preg_replace('/a\.b\./i', 'alt.binaries.', $blacklistArray['groupname']),
                'regex' => $blacklistArray['regex'],
                'status' => $blacklistArray['status'],
                'description' => $blacklistArray['description'],
                'optype' => $blacklistArray['optype'],
                'msgcol' => $blacklistArray['msgcol'],
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $blacklistArray
     */
    public function addBlacklist(array $blacklistArray): void
    {
        BinaryBlacklist::query()->insert(
            [
                'groupname' => $blacklistArray['groupname'] === '' ? 'null' : preg_replace('/a\.b\./i', 'alt.binaries.', $blacklistArray['groupname']),
                'regex' => $blacklistArray['regex'],
                'status' => $blacklistArray['status'],
                'description' => $blacklistArray['description'],
                'optype' => $blacklistArray['optype'],
                'msgcol' => $blacklistArray['msgcol'],
            ]
        );
    }

    private function retrieveLists(string $groupName): void
    {
        if (! isset($this->blackList[$groupName])) {
            $this->blackList[$groupName] = $this->getBlacklist(true, BlacklistConstants::OPTYPE_BLACKLIST, $groupName, true);
        }
        if (! isset($this->whiteList[$groupName])) {
            $this->whiteList[$groupName] = $this->getBlacklist(true, BlacklistConstants::OPTYPE_WHITELIST, $groupName, true);
        }
        $this->listsFound[$groupName] = ($this->blackList[$groupName] || $this->whiteList[$groupName]);
    }

    /**
     * PHP stays the default for summarizeBlacklistEvaluationWindow(): the paired benchmark in
     * tests/Unit/RustCollectionCleaningRemoverBlacklistHandlerW40ParityTest.php does not clear 1.5x.
     */
    public const bool W401_RUST_FFI_DEFAULT = false;

    private const array W401_OUTCOME_KEYS = ['checked', 'invalid_rows', 'whitelisted', 'blacklisted', 'skipped_disabled', 'unmatched', 'distinct_rules'];

    private static ?bool $w401UseRustFfi = null;

    private static FFI|false|null $w401Ffi = null;

    /**
     * Summarizes blacklist evaluation outcomes (`status`, `optype`, `rule_id`): outcomes whose rule status is not enabled are
     * skipped, enabled ones count by blacklist/whitelist operation type (any other type is unmatched), and matched outcomes
     * whose rule id lies in 1..`$maxRuleId` count toward the distinct matched rules. Non-array outcomes are invalid.
     *
     * @param  array<array-key, mixed>  $matchOutcomes
     * @return array<string, int>
     */
    public static function summarizeBlacklistEvaluationWindow(array $matchOutcomes, int $maxRuleId = 1000000): array
    {
        return (self::usesW401RustFfi() ? self::summarizeBlacklistEvaluationWindowNative($matchOutcomes, $maxRuleId) : null)
            ?? self::summarizeBlacklistEvaluationWindowPhp($matchOutcomes, $maxRuleId);
    }

    /**
     * @param  array<array-key, mixed>  $outcomes
     * @return array<string, int>
     */
    private static function summarizeBlacklistEvaluationWindowPhp(array $outcomes, int $maxRuleId): array
    {
        $summary = array_fill_keys(self::W401_OUTCOME_KEYS, 0);
        $summary['checked'] = count($outcomes);
        $rules = [];
        foreach ($outcomes as $outcome) {
            if (! is_array($outcome)) {
                $summary['invalid_rows']++;

                continue;
            }
            if (CollectionCleanupService::w401Int($outcome['status'] ?? null) !== BlacklistConstants::BLACKLIST_ENABLED) {
                $summary['skipped_disabled']++;

                continue;
            }
            $type = match (CollectionCleanupService::w401Int($outcome['optype'] ?? null)) {
                BlacklistConstants::OPTYPE_BLACKLIST => 'blacklisted',
                BlacklistConstants::OPTYPE_WHITELIST => 'whitelisted',
                default => 'unmatched',
            };
            $summary[$type]++;
            $id = $type === 'unmatched' ? null : CollectionCleanupService::w401Int($outcome['rule_id'] ?? null);
            if ($id !== null && $id >= 1 && $id <= $maxRuleId) {
                $rules[$id] = true;
            }
        }
        $summary['distinct_rules'] = count($rules);

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $outcomes
     * @return array<string, int>|null
     */
    private static function summarizeBlacklistEvaluationWindowNative(array $outcomes, int $maxRuleId): ?array
    {
        $words = [$maxRuleId, count($outcomes)];
        $blob = [];
        foreach ($outcomes as $outcome) {
            if (is_array($outcome)) {
                CollectionCleanupService::w401PackRow($words, $blob, [$outcome['status'] ?? null, $outcome['optype'] ?? null, $outcome['rule_id'] ?? null]);
            } else {
                $words[] = 0;
            }
        }
        $values = CollectionCleanupService::w401Invoke(self::$w401Ffi, 4, $words, $blob);

        return $values !== null && count($values) === count(self::W401_OUTCOME_KEYS) ? array_combine(self::W401_OUTCOME_KEYS, $values) : null;
    }

    public static function useW401RustFfi(?bool $enabled): void
    {
        self::$w401UseRustFfi = $enabled;
    }

    public static function usesW401RustFfi(): bool
    {
        return self::$w401UseRustFfi ?? self::W401_RUST_FFI_DEFAULT;
    }

    public static function resetW401RustFfi(): void
    {
        self::$w401UseRustFfi = null;
        self::$w401Ffi = null;
    }

    /**
     * Enabled: the paired benchmark in the W53 parity test measures 3.03x common mean, 2.74x common
     * p99, 3.56x uncommon mean and 3.46x uncommon p99 (per-metric minimum of three runs), above the
     * 1.5x rule.
     */
    private const bool W53_RUST_FFI_DEFAULT = true;

    private const string W53_FFI_CDEF = 'ssize_t nntmux_w531_blacklist_literal_first_match_batch(const char *literals, size_t literals_len, const char *columns, size_t columns_len, size_t rule_count, const char *subjects, size_t subjects_len, const char *posters, size_t posters_len, const char *message_ids, size_t message_ids_len, size_t count, int64_t *out, size_t out_cap);';

    public static function usesW53RustFfi(): bool
    {
        $enabled = self::w53RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W53_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 53 batch routed to Rust or PHP, then restores the routing. */
    public static function withW53RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w53RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w53RustFfiState('set', $previous);
        }
    }

    /**
     * For each article the index of the first `[column, literal]` rule whose literal occurs
     * case-insensitively (PHP `stripos()`) in that column, or -1. Columns follow
     * {@see BlacklistConstants}: 1 subject, 2 poster (`From`), 3 Message-ID.
     *
     * @param  array<array-key, array{0: int, 1: string}>  $rules
     * @param  array<array-key, string>  $subjects
     * @param  array<array-key, string>  $posters
     * @param  array<array-key, string>  $messageIds
     * @return list<int>
     *
     * @throws \InvalidArgumentException when the article columns differ in length or a rule column is not 1-3
     */
    public static function firstLiteralRuleMatches(array $rules, array $subjects, array $posters, array $messageIds): array
    {
        return self::usesW53RustFfi() ? self::firstLiteralRuleMatchesNative($rules, $subjects, $posters, $messageIds) : self::firstLiteralRuleMatchesPhp($rules, $subjects, $posters, $messageIds);
    }

    /**
     * @param  array<array-key, array{0: int, 1: string}>  $rules
     * @param  array<array-key, string>  $subjects
     * @param  array<array-key, string>  $posters
     * @param  array<array-key, string>  $messageIds
     * @return list<int>
     */
    public static function firstLiteralRuleMatchesPhp(array $rules, array $subjects, array $posters, array $messageIds): array
    {
        self::w53Count($subjects, $posters, $messageIds);
        $rules = self::w53Rules($rules);
        $posters = array_values($posters);
        $messageIds = array_values($messageIds);
        $hits = [];
        foreach (array_values($subjects) as $i => $subject) {
            $hit = -1;
            foreach ($rules as $r => [$column, $literal]) {
                if (stripos($column === 1 ? $subject : ($column === 2 ? $posters[$i] : $messageIds[$i]), $literal) !== false) {
                    $hit = $r;
                    break;
                }
            }
            $hits[] = $hit;
        }

        return $hits;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or declines the batch (a rule or
     * article field holding a NUL byte, or more than 4096 rules).
     *
     * @param  array<array-key, array{0: int, 1: string}>  $rules
     * @param  array<array-key, string>  $subjects
     * @param  array<array-key, string>  $posters
     * @param  array<array-key, string>  $messageIds
     * @return list<int>
     */
    public static function firstLiteralRuleMatchesNative(array $rules, array $subjects, array $posters, array $messageIds): array
    {
        $count = self::w53Count($subjects, $posters, $messageIds);
        $rules = self::w53Rules($rules);
        if ($rules === []) {
            return array_fill(0, $count, -1);
        }
        $buffer = $count === 0 ? null : self::w53Buffer($count);
        if ($buffer === null) {
            return self::firstLiteralRuleMatchesPhp($rules, $subjects, $posters, $messageIds);
        }
        [$ffi, $out, $cap] = $buffer;
        $literals = implode("\0", array_column($rules, 1));
        $columns = pack('P*', ...array_column($rules, 0));
        $joinedSubjects = implode("\0", $subjects);
        $joinedPosters = implode("\0", $posters);
        $joinedIds = implode("\0", $messageIds);
        try {
            // @phpstan-ignore method.notFound
            $words = self::w53Decode($out, $ffi->nntmux_w531_blacklist_literal_first_match_batch($literals, \strlen($literals), $columns, \strlen($columns), \count($rules), $joinedSubjects, \strlen($joinedSubjects), $joinedPosters, \strlen($joinedPosters), $joinedIds, \strlen($joinedIds), $count, $out, $cap), $count);
        } catch (\Throwable) {
            $words = null;
        }

        return $words !== null && \count($words) === $count ? $words : self::firstLiteralRuleMatchesPhp($rules, $subjects, $posters, $messageIds);
    }

    /**
     * @param  array<array-key, array{0: int, 1: string}>  $rules
     * @return list<array{0: int, 1: string}>
     */
    private static function w53Rules(array $rules): array
    {
        foreach ($rules as $rule) {
            if (! \in_array($rule[0], [1, 2, 3], true)) {
                throw new \InvalidArgumentException('Literal rule column must be 1, 2 or 3.');
            }
        }

        return array_values($rules);
    }

    /**
     * @param  array<mixed>  ...$columns
     */
    private static function w53Count(array ...$columns): int
    {
        $count = \count($columns[0]);
        foreach ($columns as $column) {
            if (\count($column) !== $count) {
                throw new \InvalidArgumentException('Blacklist article columns must have the same length.');
            }
        }

        return $count;
    }

    /**
     * Lazily resolved binding plus the reusable output buffer sized for `$words` words.
     *
     * @return array{0: FFI, 1: CData, 2: int}|null
     */
    private static function w53Buffer(int $words): ?array
    {
        $buffer = self::w53RustFfiState('out', $words);

        return \is_array($buffer) ? $buffer : null;
    }

    /** @return list<int>|null The `$written` words the export reported, or null when it declined. */
    private static function w53Decode(CData $out, mixed $written, int $maxWords): ?array
    {
        if (! \is_int($written) || $written < 0 || $written > $maxWords) {
            return null;
        }
        if ($written === 0) {
            return [];
        }
        $decoded = unpack('P*', FFI::string($out, $written * 8));

        /** @var list<int>|null */
        return \is_array($decoded) ? array_values($decoded) : null;
    }

    /**
     * Wave 53 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics.
     */
    private static function w53RustFfiState(?string $op = null, mixed $val = null): mixed
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
                $ffi ??= RustPipelineLibrary::resolveDomain('obfuscation_blacklist_cat_w53', self::W53_FFI_CDEF) ?? false;
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
