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

    // ========================================================================
    // WAVE 65.4 BATCH HELPER
    // ========================================================================

    /** PHP retained: the minimum of three 1000-round paired runs reached 1.80x common mean, 1.80x common p99, 0.93x uncommon mean and 0.94x uncommon p99, below the 1.5x gate; see RustUsenetDownloadAdminSnapshotAnidbArchiveBlacklistW65ParityTest. */
    public const bool W65_RUST_FFI_DEFAULT = false;

    private const string W65_FFI_CDEF = 'ssize_t nntmux_w654_blacklist_eval_plan_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per w65BlacklistEvalPlanBatch() record. */
    public const int W65_ROW_WORDS = 3;

    /** int64 summary words after the w65BlacklistEvalPlanBatch() records (none for an empty batch). */
    public const int W65_TRAILER_WORDS = 12;

    private const string W65_METACHARACTERS = '.[]()*+?{}^$';

    private const string W65_ALNUM = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * Blacklist rule evaluation rows for [subject, poster, message ID, group, rule pattern, rule
     * group, message column, operation type, status, rule ID] items (one article/rule pair each).
     *
     * Decisions in order: 1 disabled (status other than BLACKLIST_ENABLED), 2 group skip (the
     * trimmed rule group is empty or `*`, a case-insensitive `prefix*`, else a case-insensitive
     * equal of the trimmed group), 3 invalid column or operation type, 4 empty pattern, 5 needs
     * PCRE, then 6 blacklist hit, 7 whitelist hit, 8 blacklist miss, 9 whitelist miss. A literal
     * pattern is an optional leading `^`, `|`-separated non-empty alternatives of plain bytes or
     * backslash-escaped non-alphanumeric bytes, and an optional final `$`; anchors only combine
     * with a single alternative. Any other metacharacter, `\` before an alphanumeric or at the
     * end, or an empty alternative needs PCRE. Alternatives match the column case-insensitively
     * (ASCII) in order: equal with both anchors, prefix, suffix, else substring; the first hit
     * gives the offset. The first hit of each rule ID asks for a usage counter update.
     *
     * Rows [decision | column (0 when invalid) << 4 | flags << 8 (1 start anchor, 2 end anchor,
     * 4 first hit of the rule, 8 escapes, 16 empty column) | alternatives << 16 | matched
     * alternative (1-based) << 24 (both clamped to 255) | pattern length << 32 (clamped to
     * 2^16 - 1), match offset (-1 when none), rule ID], then the trailer [records, the nine
     * decision counts, distinct hit rule IDs, FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: int, 7: int, 8: int, 9: int}>  $items
     * @return list<int>
     */
    public static function w65BlacklistEvalPlanBatch(array $items): array
    {
        return self::usesW65RustFfi() ? self::w65BlacklistEvalPlanBatchNative($items) : self::w65BlacklistEvalPlanBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: int, 7: int, 8: int, 9: int}>  $items
     * @return list<int>
     */
    public static function w65BlacklistEvalPlanBatchPhp(array $items): array
    {
        self::w65Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $t = [\count($items), 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
        $hits = [];
        $digest = 2_166_136_261;
        foreach ($items as [$subject, $poster, $messageId, $group, $pattern, $ruleGroup, $column, $opType, $status, $ruleId]) {
            $field = match ($column) {
                BlacklistConstants::BLACKLIST_FIELD_SUBJECT => $subject,
                BlacklistConstants::BLACKLIST_FIELD_FROM => $poster,
                BlacklistConstants::BLACKLIST_FIELD_MESSAGEID => $messageId,
                default => null,
            };
            $flags = $field === '' ? 16 : 0;
            $alternatives = $matched = 0;
            $offset = -1;
            if ($status !== BlacklistConstants::BLACKLIST_ENABLED) {
                $decision = 1;
            } elseif (! self::w65GroupMatches($group, $ruleGroup)) {
                $decision = 2;
            } elseif ($field === null || ($opType !== BlacklistConstants::OPTYPE_BLACKLIST && $opType !== BlacklistConstants::OPTYPE_WHITELIST)) {
                $decision = 3;
            } elseif ($pattern === '') {
                $decision = 4;
            } elseif (($rule = self::w65ParseRule($pattern)) === null) {
                $decision = 5;
            } else {
                [$start, $end, $escaped, $literals] = $rule;
                $alternatives = \count($literals);
                $flags |= ($start ? 1 : 0) | ($end ? 2 : 0) | ($escaped ? 8 : 0);
                $hay = strtolower($field);
                foreach ($literals as $k => $literal) {
                    $found = match (true) {
                        $start && $end => $hay === $literal ? 0 : false,
                        $start => str_starts_with($hay, $literal) ? 0 : false,
                        $end => str_ends_with($hay, $literal) ? \strlen($hay) - \strlen($literal) : false,
                        default => strpos($hay, $literal),
                    };
                    if ($found !== false) {
                        $matched = $k + 1;
                        $offset = $found;
                        break;
                    }
                }
                $black = $opType === BlacklistConstants::OPTYPE_BLACKLIST;
                if ($matched > 0) {
                    if (! isset($hits[$ruleId])) {
                        $hits[$ruleId] = true;
                        $flags |= 4;
                    }
                    $decision = $black ? 6 : 7;
                } else {
                    $decision = $black ? 8 : 9;
                }
            }
            $word = $decision | ($field === null ? 0 : $column) << 4 | $flags << 8 | min($alternatives, 255) << 16 | min($matched, 255) << 24
                | min(\strlen($pattern), 0xFFFF) << 32;
            array_push($out, $word, $offset, $ruleId);
            $t[$decision]++;
            $value = $word ^ $offset << 20 ^ $ruleId;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $t[10] = \count($hits);
        $t[11] = $digest;

        return [...$out, ...$t];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: int, 7: int, 8: int, 9: int}>  $items
     * @return list<int>
     */
    public static function w65BlacklistEvalPlanBatchNative(array $items): array
    {
        self::w65Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w65Join([array_column($items, 0), array_column($items, 1), array_column($items, 2), array_column($items, 3), array_column($items, 4), array_column($items, 5)]);
        $ints = pack('P*', ...array_column($items, 6), ...array_column($items, 7), ...array_column($items, 8), ...array_column($items, 9));

        return self::w65Call($blob, $twin, $ints, $count, $count * self::W65_ROW_WORDS + self::W65_TRAILER_WORDS)
            ?? self::w65BlacklistEvalPlanBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w65Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new \InvalidArgumentException('W65 blacklist evaluation plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 10 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_string($item[2]) || ! \is_string($item[3]) || ! \is_string($item[4]) || ! \is_string($item[5]) || ! \is_int($item[6]) || ! \is_int($item[7]) || ! \is_int($item[8]) || ! \is_int($item[9])) {
                throw new \InvalidArgumentException('W65 blacklist evaluation plan batch items must be [string subject, string poster, string message ID, string group, string rule pattern, string rule group, int message column, int operation type, int status, int rule ID] lists.');
            }
        }
    }

    public static function usesW65RustFfi(): bool
    {
        $enabled = self::w65RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W65_RUST_FFI_DEFAULT;
    }

    public static function enableW65RustFfi(): void
    {
        self::w65RustFfiState('enabled', true);
    }

    public static function disableW65RustFfi(): void
    {
        self::w65RustFfiState('enabled', false);
    }

    /** Drop the W65 override, binding, output buffer and declined-call count; routing returns to W65_RUST_FFI_DEFAULT. */
    public static function resetW65RustFfi(): void
    {
        self::w65RustFfiState('reset');
    }

    /**
     * Run $callback with the W65 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW65RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w65RustFfiState('enabled');
        self::w65RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w65RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w65Join(array $columns): array
    {
        $values = array_merge(...$columns);
        $joined = implode("\0", $values);

        return substr_count($joined, "\0") === \count($values) - 1 ? [$joined, ''] : [$joined, implode("\x01", $values)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w65Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w65RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w65', self::W65_FFI_CDEF) ?? false;
            self::w65RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w65RustFfiState('out', $out);
                self::w65RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W65_FFI_CDEF.)
            $written = $ffi->nntmux_w654_blacklist_eval_plan_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (\Throwable) {
        }
        self::w65RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W65 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static so the class declares no new property: no arguments returns the state,
     * one argument reads a key ('reset' clears everything) and two arguments write a key.
     */
    private static function w65RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }

    /**
     * A literal rule as [start anchor, end anchor, escapes, lower-cased alternatives], or null
     * when it needs PCRE.
     *
     * @return array{0: bool, 1: bool, 2: bool, 3: list<string>}|null
     */
    private static function w65ParseRule(string $pattern): ?array
    {
        $length = \strlen($pattern);
        $start = $length > 0 && $pattern[0] === '^';
        $end = $escaped = false;
        $literals = [];
        $current = '';
        for ($i = $start ? 1 : 0; $i < $length; $i++) {
            $c = $pattern[$i];
            if ($c === '\\') {
                if ($i + 1 >= $length || str_contains(self::W65_ALNUM, $pattern[$i + 1])) {
                    return null;
                }
                $current .= $pattern[++$i];
                $escaped = true;
            } elseif ($c === '|') {
                $literals[] = strtolower($current);
                $current = '';
            } elseif ($c === '$' && $i === $length - 1) {
                $end = true;
            } elseif (str_contains(self::W65_METACHARACTERS, $c)) {
                return null;
            } else {
                $current .= $c;
            }
        }
        $literals[] = strtolower($current);
        if (\in_array('', $literals, true) || (($start || $end) && \count($literals) > 1)) {
            return null;
        }

        return [$start, $end, $escaped, $literals];
    }

    private static function w65GroupMatches(string $group, string $ruleGroup): bool
    {
        $ruleGroup = trim($ruleGroup);
        if ($ruleGroup === '' || $ruleGroup === '*') {
            return true;
        }
        $group = strtolower(trim($group));
        if (str_ends_with($ruleGroup, '*')) {
            return str_starts_with($group, strtolower(substr($ruleGroup, 0, -1)));
        }

        return $group === strtolower($ruleGroup);
    }
}
