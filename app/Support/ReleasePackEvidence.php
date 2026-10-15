<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\NameFixing\SeasonPackDetector;
use App\Services\Nzb\NzbService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Classification and admission for one exact release/NZB generation. */
final class ReleasePackEvidence
{
    public const string COLUMN = 'additional_pack_evidence';

    /** @return array{present: bool, id: int, guid: string, name: mixed, searchname: mixed, value: mixed} */
    public static function observe(object $release): array
    {
        $attributes = method_exists($release, 'getAttributes') ? $release->getAttributes() : get_object_vars($release);

        return ['present' => array_key_exists(self::COLUMN, $attributes),
            'id' => (int) ($release->releases_id ?? $release->id ?? 0), 'guid' => (string) ($release->guid ?? ''),
            'name' => $attributes['name'] ?? null, 'searchname' => $attributes['searchname'] ?? null,
            'value' => $attributes[self::COLUMN] ?? null];
    }

    /** @return array{kind: string, epoch: ?string, state: ?string} */
    public static function decode(object $release): array
    {
        $observation = self::observe($release);
        if (! $observation['present']) {
            throw new LogicException('Missing releases.additional_pack_evidence migration or release projection.');
        }
        if ($observation['value'] === null) {
            return ['kind' => 'absent', 'epoch' => null, 'state' => null];
        }
        $value = is_string($observation['value']) ? json_decode($observation['value'], true) : $observation['value'];
        if (! is_array($value) || ($value['v'] ?? null) !== 1 || ! array_key_exists('state', $value)
            || ! in_array($value['state'], [null, 'probable', 'confirmed'], true)
            || ! is_string($value['epoch'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $value['epoch']) !== 1
            || ! is_string($value['identity_sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $value['identity_sha256']) !== 1) {
            return ['kind' => 'invalid', 'epoch' => null, 'state' => null];
        }

        return ['kind' => hash_equals(self::identity($observation['id'], $observation['guid']), $value['identity_sha256']) ? 'current' : 'other',
            'epoch' => $value['epoch'], 'state' => $value['state']];
    }

    /** @param array{present: bool, id: int, guid: string, name: mixed, searchname: mixed, value: mixed} $observed */
    public static function matches(object $current, array $observed, bool $titles = true): bool
    {
        $now = self::observe($current);
        self::decode($current);
        if (! $observed['present'] || $now['id'] !== $observed['id'] || $now['guid'] !== $observed['guid']
            || ($titles && ($now['name'] !== $observed['name'] || $now['searchname'] !== $observed['searchname']))) {
            return false;
        }
        if ($observed['value'] === null || $now['value'] === null) {
            return $observed['value'] === $now['value'];
        }
        $before = (object) ['id' => $observed['id'], 'guid' => $observed['guid'], self::COLUMN => $observed['value']];
        $old = self::decode($before);
        $new = self::decode($current);

        return $old['kind'] !== 'invalid' && $new['kind'] !== 'invalid'
            && $old['kind'] === $new['kind'] && $old['epoch'] === $new['epoch'];
    }

    /**
     * @param  array{token: ?string, claimed_at: ?string}|null  $owner
     *
     * @phpstan-impure
     */
    public static function ownsClaim(object $current, ?array $owner): bool
    {
        if ($owner === null) {
            return true;
        }
        $token = $current->additional_pp_claim_token ?? null;
        $at = $current->additional_pp_claimed_at ?? null;
        if ($token !== $owner['token'] || ($at === null ? null : (string) $at) !== $owner['claimed_at']) {
            return false;
        }

        return $token === null || ($at !== null && Carbon::parse((string) $at)->greaterThan(AdditionalCandidateQuery::claimStaleBefore()));
    }

    /**
     * A non-null reason distinguishes a temporary deferral from a confirmed pack rejection.
     *
     * @param  array{present: bool, id: int, guid: string, name: mixed, searchname: mixed, value: mixed}  $observed
     * @param  array{token: ?string, claimed_at: ?string}|null  $owner
     */
    public static function candidateRejection(object $current, array $observed, string $title, ?array $owner = null): ?string
    {
        if (! self::matches($current, $observed)) {
            return 'stale';
        }
        if ((int) ($current->nzbstatus ?? 0) !== NzbService::NZB_ADDED) {
            return 'unpublished';
        }
        $evidence = self::decode($current);
        if ($evidence['kind'] === 'invalid') {
            return 'invalid';
        }
        if (! self::ownsClaim($current, $owner)) {
            return 'claimed';
        }
        if (! SeasonPackDetector::isEpisodeName($title)) {
            return null;
        }
        if ($evidence['kind'] === 'current' && $evidence['state'] !== null) {
            return $evidence['state'];
        }
        if (SeasonPackDetector::isSeasonPack((string) $current->searchname)) {
            return 'confirmed';
        }
        $claimedAt = $current->additional_pp_claimed_at ?? null;
        $active = ($current->additional_pp_claim_token ?? null) !== null && $claimedAt !== null
            && Carbon::parse((string) $claimedAt)->greaterThan(AdditionalCandidateQuery::claimStaleBefore());
        if (($active && $owner === null) || ! self::ownsClaim($current, $owner)) {
            return 'claimed';
        }

        return null;
    }

    /** @param array{present: bool, id: int, guid: string, name: mixed, searchname: mixed, value: mixed} $observed */
    public static function merge(object $current, array $observed, string $intent): ?string
    {
        if (! self::matches($current, $observed)) {
            throw new LogicException('Stale pack evidence observation cannot publish.');
        }
        $evidence = self::decode($current);
        if ($evidence['kind'] === 'invalid') {
            throw new LogicException('Invalid pack evidence cannot be replaced by an observation.');
        }
        $state = $evidence['kind'] === 'current' ? $evidence['state'] : null;
        $next = match (true) {
            $state === 'confirmed', $intent === 'confirmed' => 'confirmed',
            $intent === 'probable' => 'probable',
            $state === 'probable' && $intent !== 'clear-probable' => 'probable',
            default => null,
        };
        if ($evidence['kind'] === 'current' && $state === $next) {
            return self::rawValue($current);
        }
        if ($evidence['kind'] === 'absent' && $next === null) {
            return null;
        }

        return self::encode($current, $next, $evidence['kind'] === 'current' ? $evidence['epoch'] : null);
    }

    public static function sameStoredValue(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }
        $left = is_string($left) ? json_decode($left, true) : $left;
        $right = is_string($right) ? json_decode($right, true) : $right;
        if (! is_array($left) || ! is_array($right)) {
            return false;
        }
        ksort($left);
        ksort($right);

        return $left === $right;
    }

    public static function tombstone(object $current): string
    {
        return self::encode($current, null);
    }

    /**
     * Retire this unpublished cohort's evidence and return its generation-specific reset.
     * The caller applies these values with publication in the same locked transaction.
     *
     * @return array<string, int|null>
     */
    public static function retireDerivedEvidence(int $releaseId): array
    {
        foreach (['release_files', 'release_nfos', 'media_infos', 'par_hashes', 'par_file_hashes', 'par_file_hash_inventories', 'par2_hash_retry', 'par2_rename_progress', 'par2_descriptor_evidence'] as $table) {
            DB::table($table)->where('releases_id', $releaseId)->delete();
        }

        return [
            'nfostatus' => -1,
            'haspreview' => -1,
            'passwordstatus' => config('nntmux_settings.check_passworded_rars') === true ? -1 : 0,
            'proc_nfo' => 0,
            'proc_files' => 0,
            'proc_srr' => 0,
            'proc_crc32' => 0,
            'proc_uid' => 0,
            'proc_par2' => 0,
            'proc_hash16k' => 0,
            'rarinnerfilecount' => 0,
            'pp_timeout_count' => 0,
            'archive_retry_at' => null,
            'additional_pp_claim_token' => null,
            'additional_pp_claimed_at' => null,
        ];
    }

    private static function identity(int $id, string $guid): string
    {
        return hash('sha256', json_encode([(string) $id, $guid], JSON_THROW_ON_ERROR));
    }

    private static function encode(object $current, ?string $state, ?string $epoch = null): string
    {
        return json_encode(['v' => 1, 'identity_sha256' => self::identity((int) ($current->releases_id ?? $current->id), (string) $current->guid),
            'epoch' => $epoch ?? bin2hex(random_bytes(32)), 'state' => $state], JSON_THROW_ON_ERROR);
    }

    private static function rawValue(object $release): ?string
    {
        $value = self::observe($release)['value'];

        return is_string($value) || $value === null ? $value : json_encode($value, JSON_THROW_ON_ERROR);
    }
}
