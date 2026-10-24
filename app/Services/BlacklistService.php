<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BlacklistConstants;
use App\Models\BinaryBlacklist;
use App\Support\RustPipelineLibrary;
use FFI;
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
}
