<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Support\PredbSearchDocument;

final class DonorMatchSelector
{
    public const PREDB_IDENTITY_POLICY = '911cad40abd7de92b23adad58fd2a3daa045ea988f13bed35078805edf49f6cd';

    /** @param list<object> $donors */
    public function selectCanonical(array $donors): ?object
    {
        if (count($donors) > 256) {
            return null;
        }
        $identity = null;
        $selected = null;
        foreach ($donors as $donor) {
            $title = (string) ($donor->canonical_title ?? '');
            $searchName = (string) ($donor->searchname ?? '');
            if ((int) ($donor->predb_id ?? 0) <= 0 || trim($title) === '' || ! mb_check_encoding($title, 'UTF-8')
                || ! mb_check_encoding($searchName, 'UTF-8') || mb_strlen($title, 'UTF-8') > 255
                || preg_match('/\p{C}/u', $title) !== 0
                || PredbSearchDocument::exactValue($title) !== PredbSearchDocument::exactValue($searchName)) {
                return null;
            }
            $currentIdentity = (int) $donor->predb_id.':'.PredbSearchDocument::exactValue($title);
            if ($identity !== null && $identity !== $currentIdentity) {
                return null;
            }
            $identity = $currentIdentity;
            if ($selected === null || (int) $donor->releases_id < (int) $selected->releases_id) {
                $selected = clone $donor;
                $selected->searchname = $title;
            }
        }

        return $selected;
    }

    /**
     * @param  list<object>  $donors
     */
    public function select(array $donors, int $targetSize, int $tolerancePercent): ?object
    {
        if ($targetSize <= 0 || $donors === []) {
            return null;
        }

        $eligible = [];

        foreach ($donors as $donor) {
            $donorSize = (int) ($donor->relsize ?? $donor->size ?? 0);
            if ($donorSize <= 0) {
                continue;
            }

            $difference = abs($donorSize - $targetSize);
            $differencePercent = ($difference / $donorSize) * 100;

            if ($differencePercent <= $tolerancePercent) {
                $eligible[] = [
                    'donor' => $donor,
                    'difference' => $difference,
                    'id' => (int) ($donor->releases_id ?? $donor->id ?? PHP_INT_MAX),
                ];
            }
        }

        if ($eligible === []) {
            return null;
        }

        usort($eligible, static function (array $left, array $right): int {
            return [$left['difference'], $left['id']] <=> [$right['difference'], $right['id']];
        });

        return $eligible[0]['donor'];
    }
}
