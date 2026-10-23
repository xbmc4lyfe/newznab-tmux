<?php

declare(strict_types=1);

namespace App\Services\Predb;

use App\Services\NameFixing\OriginalFilenameEvidence;
use App\Support\PredbSearchDocument;
use App\Support\RustPipelineLibrary;

/** Complete bounded nominations for one SQL owner; keys never establish identity. */
final class PredbIdentityKeys
{
    public const PREDB_IDENTITY_POLICY = 'cb4556aa6ea5ca655acac3e5f595752c5865bff42475801082b31cbb454de337';

    /** @return list<array{kind: int, key_digest: string}>|null */
    public function forRow(object $row): ?array
    {
        $id = $row->id ?? null;
        if (is_int($id)) {
            if ($id < 1 || $id > 4294967295) {
                return null;
            }
        } elseif (! is_string($id) || preg_match('/\A[1-9][0-9]{0,9}\z/D', $id) !== 1 || (int) $id > 4294967295) {
            return null;
        }
        $title = $row->title ?? null;
        $filename = $row->filename ?? null;
        if (! is_string($title) || ! is_string($filename)) {
            return null;
        }

        $supported = false;
        $ffiRes = RustPipelineLibrary::predbIdentityKeysForRow($title, $filename, null, $supported);
        if ($supported) {
            return $ffiRes;
        }

        return $this->forRowPhp($title, $filename);
    }

    /** @return list<array{kind: int, key_digest: string}>|null */
    public function forRowPhp(string $title, string $filename): ?array
    {
        if ($title === '' || ! $this->supportedText($title)
            || preg_match('~[\\\\/|]~', $title) !== 0
            || ! $this->supportedText($filename)) {
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
        $aliases = (new OriginalFilenameEvidence($filename))->canonicalNominationKeysPhp();
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
