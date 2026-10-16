<?php

declare(strict_types=1);

namespace App\Services\Predb;

use App\Services\NameFixing\OriginalFilenameEvidence;
use App\Support\PredbSearchDocument;

/** Complete bounded nominations for one SQL owner; keys never establish identity. */
final class PredbIdentityKeys
{
    public const PREDB_IDENTITY_POLICY = '7879261eb753e6011f1072f9a12a195dc1ebec2ba230071af946d8bf14cdd8f1';

    /** @return list<array{kind: int, key_digest: string}>|null */
    public function forRow(object $row): ?array
    {
        $id = $row->id ?? null;
        if ((! is_int($id) && ! is_string($id))
            || preg_match('/\A[1-9][0-9]{0,9}\z/D', (string) $id) !== 1
            || (int) $id > 4294967295) {
            return null;
        }
        $title = $row->title ?? null;
        $filename = $row->filename ?? null;
        if (! is_string($title) || $title === '' || ! $this->supportedText($title)
            || preg_match('~[\\\\/|]~', $title) !== 0
            || ! is_string($filename) || ! $this->supportedText($filename)) {
            return null;
        }
        $canonical = PredbSearchDocument::exactValue($title);
        if ($canonical === '' || strlen($canonical) > 8192) {
            return null;
        }
        $keys = [['kind' => 1, 'key_digest' => hash('sha256', $canonical)]];
        if ($filename === '') {
            return $keys;
        }
        $aliases = (new OriginalFilenameEvidence($filename))->canonicalNominationKeys();
        if ($aliases === null) {
            return null;
        }
        $digests = [];
        foreach ($aliases as $alias) {
            $digest = hash('sha256', $alias);
            if (! isset($digests[$digest])) {
                $keys[] = ['kind' => 2, 'key_digest' => $digest];
                $digests[$digest] = true;
            }
        }

        return $keys;
    }

    private function supportedText(string $value): bool
    {
        // The persisted source columns are varchar(255); retain an explicit byte-work bound.
        return strlen($value) <= 1020 && mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value, 'UTF-8') <= 255 && preg_match('/\p{C}/u', $value) === 0;
    }
}
