<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Models\Category;
use App\Models\Predb;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\PredbIdentityKey;
use App\Services\ReleaseCleaningService;
use App\Support\PredbSearchDocument;
use App\Support\Utf8;

class ReleaseMetadataResolver
{
    public function __construct(private readonly ReleaseCleaningService $cleaning, private readonly ?PayloadIdentityResolver $payloadIdentity = null, private readonly ?CategorizationService $categorization = null) {}

    /** @param array<string, mixed> $manifest
     * @return array{name: string, searchname: string, fromname: string, predb_id: int, isrenamed: int, categories_id: int, naming_regex_id: int}
     */
    public function resolve(array $manifest): array
    {
        $rawName = Utf8::clean((string) $manifest['subject']);
        $from = Utf8::clean((string) $manifest['fromname']);
        $group = $manifest['groups'][0];
        $cleaned = $this->cleaning->releaseCleaner($rawName, $from, $group);
        $name = is_array($cleaned) ? (string) ($cleaned['cleansubject'] ?? $rawName) : $rawName;
        $proper = is_array($cleaned) ? (bool) ($cleaned['properlynamed'] ?? false) : true;
        $predb = is_array($cleaned) ? max(0, (int) ($cleaned['predb'] ?? 0)) : 0;
        if ($predb === 0 && $name !== '') {
            $match = Predb::matchPre($name);
            if ($match !== false) {
                $name = $match['title'];
                $predb = (int) $match['predb_id'];
                $proper = true;
            }
        }

        if (! $proper && $predb === 0) {
            $candidate = ($this->payloadIdentity ?? new PayloadIdentityResolver($this->cleaning))->resolve($manifest);
            if ($candidate !== null) {
                $existing = $this->cleaning->releaseNameFromFile($name);
                if ($existing === null || PredbIdentityKey::normalize($existing) === PredbIdentityKey::normalize($candidate)) {
                    $name = $candidate;
                    $proper = true;
                    $match = Predb::matchPre($name);
                    if ($match !== false && PredbSearchDocument::exactValue($match['title']) === PredbSearchDocument::exactValue($name)) {
                        $name = $match['title'];
                        $predb = (int) $match['predb_id'];
                    }
                }
            }
        }

        $category = ($this->categorization ?? new CategorizationService)->determineCategory((int) $manifest['groups_id'], $name, $from);

        return ['name' => $rawName, 'searchname' => Utf8::clean($name), 'fromname' => $from,
            'predb_id' => $predb, 'isrenamed' => $proper ? 1 : 0,
            'categories_id' => $category['categories_id'] ?? Category::OTHER_MISC,
            'naming_regex_id' => is_array($cleaned) ? (int) ($cleaned['id'] ?? 0) : 0];
    }
}
