<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Exceptions\NzbPasswordMetadataRejected;
use App\Services\Nzb\NzbXmlElements;
use RuntimeException;

final class NzbManifestReader
{
    /** @return array<string, mixed> */
    public function read(string $xml, object $release): array
    {
        if (strlen($xml) > 268435456) {
            throw new RuntimeException('NZB exceeds audit metadata budget.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($xml, options: LIBXML_NONET);
            if ($document === false || $document->getName() !== 'nzb') {
                throw new RuntimeException('Invalid NZB.');
            }
            $root = dom_import_simplexml($document);
            $namespace = $root->namespaceURI ?? '';
            if (! in_array($namespace, ['', 'http://www.newzbin.com/DTD/2003/nzb'], true)) {
                throw new RuntimeException('Invalid NZB.');
            }
            // These are routing hints only; parsed namespace URIs authorize every node.
            // Plain legacy/default-namespace documents retain their existing hot loop.
            if (str_contains($xml, 'xmlns:') || str_contains($xml, "\0")
                || $root->ownerDocument->doctype !== null
                || substr_count($xml, 'xmlns') > ($namespace === '' ? 0 : 1)) {
                return $this->readNamespacedDocument($document, $release, $namespace);
            }
            $files = [];
            $groups = [];
            $expected = 0;
            $verificationMode = null;
            $conflict = false;
            $elements = new NzbXmlElements($document);
            $passwords = $this->passwordMetadata($elements);
            foreach ($elements->metadata() as $metadata) {
                $value = (string) $metadata;
                if ((string) $metadata->attributes()['type'] === 'nntmux:verification-mode' && $value === 'spot-check') {
                    $verificationMode = 'spot_check';
                }
                if (in_array((string) $metadata->attributes()['type'], ['nntmux:verified-file-count', 'nntmux:declared-file-count'], true)
                    && preg_match('/^[1-9][0-9]{0,6}$/D', $value) === 1) {
                    $expected = max($expected, (int) $value);
                }
            }
            foreach ($document->file as $index => $file) {
                $subject = (string) $file['subject'];
                $expected = max($expected, self::declaredFileCount($subject));
                $parts = preg_match('/\(\d+\/(\d+)\)\s*$/', $subject, $count) ? (int) $count[1] : null;
                $segments = [];
                $observedParts = 0;
                $partNumbers = [];
                foreach ($file->segments->segment as $segment) {
                    $number = (int) $segment['number'];
                    $messageId = trim((string) $segment);
                    $segments[] = ['number' => $number, 'bytes' => (int) $segment['bytes'], 'messageid' => $messageId];
                    if ($number < 1 || isset($partNumbers[$number])) {
                        $conflict = true;
                    }
                    $partNumbers[$number] = true;
                    if ($number > 0 && $messageId !== '') {
                        $observedParts = max($observedParts, $number);
                    }
                }
                foreach ($file->groups->group as $group) {
                    $groups[(string) $group] = true;
                }
                $files[] = ['key' => hash('sha256', $subject), 'subject' => preg_replace('/\s*\(\d+\/\d+\)\s*$/', '', $subject),
                    'totalparts' => $parts ?? $observedParts, 'totalparts_inferred' => $parts === null, 'segments' => $segments];
            }
            if ($files === []) {
                throw new RuntimeException('NZB contains no files.');
            }

            $metadataSource = ($release->metadata_source ?? null) === 'import' ? 'import' : 'audit';
            $manifest = ['subject' => $release->searchname, 'fromname' => $release->fromname, 'date' => $release->postdate,
                'groups_id' => (int) $release->groups_id, 'regex_id' => 0, 'expected_files' => $expected,
                'groups' => array_keys($groups), 'files' => $files, 'metadata_source' => $metadataSource];
            if ($passwords !== []) {
                $manifest['passwords'] = $passwords;
            }
            if ($conflict) {
                $manifest['conflict'] = true;
            }
            if ($verificationMode !== null) {
                $manifest['verification_mode'] = $verificationMode;
            }
            if ($metadataSource === 'import' && is_int($release->categories_id ?? null) && $release->categories_id > 0) {
                $manifest['categories_id'] = $release->categories_id;
            }
            if ($metadataSource === 'import' && ($release->import_name_override ?? false) === true) {
                $manifest['import_name_override'] = true;
            }
            if ($metadataSource === 'import' && is_string($release->original_subject ?? null)) {
                $manifest['original_subject'] = $release->original_subject;
            }

            return $manifest;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return array<string, mixed> */
    private function readNamespacedDocument(\SimpleXMLElement $document, object $release, string $namespace): array
    {
        $files = [];
        $groups = [];
        $expected = 0;
        $verificationMode = null;
        $conflict = false;
        $elements = new NzbXmlElements($document);
        $passwords = $this->passwordMetadata($elements);
        foreach ($elements->metadata() as $metadata) {
            $value = (string) $metadata;
            if ((string) $metadata->attributes()['type'] === 'nntmux:verification-mode' && $value === 'spot-check') {
                $verificationMode = 'spot_check';
            }
            if (in_array((string) $metadata->attributes()['type'], ['nntmux:verified-file-count', 'nntmux:declared-file-count'], true)
                && preg_match('/^[1-9][0-9]{0,6}$/D', $value) === 1) {
                $expected = max($expected, (int) $value);
            }
        }
        foreach ($elements->files() as $file) {
            $fileAttributes = $file->attributes();
            $subject = (string) $fileAttributes['subject'];
            $expected = max($expected, self::declaredFileCount($subject));
            $parts = preg_match('/\(\d+\/(\d+)\)\s*$/', $subject, $count) ? (int) $count[1] : null;
            $segments = [];
            $observedParts = 0;
            $partNumbers = [];
            foreach ($elements->segments($file) as $segment) {
                $segmentAttributes = $segment->attributes();
                $number = (int) $segmentAttributes['number'];
                $messageId = trim((string) $segment);
                $segments[] = ['number' => $number, 'bytes' => (int) $segmentAttributes['bytes'], 'messageid' => $messageId];
                if ($number < 1 || isset($partNumbers[$number])) {
                    $conflict = true;
                }
                $partNumbers[$number] = true;
                if ($number > 0 && $messageId !== '') {
                    $observedParts = max($observedParts, $number);
                }
            }
            foreach ($elements->groups($file) as $group) {
                $groups[(string) $group] = true;
            }
            $files[] = ['key' => hash('sha256', $subject), 'subject' => preg_replace('/\s*\(\d+\/\d+\)\s*$/', '', $subject),
                'totalparts' => $parts ?? $observedParts, 'totalparts_inferred' => $parts === null, 'segments' => $segments];
        }
        if ($files === []) {
            throw new RuntimeException('NZB contains no files.');
        }

        $metadataSource = ($release->metadata_source ?? null) === 'import' ? 'import' : 'audit';
        $manifest = ['subject' => $release->searchname, 'fromname' => $release->fromname, 'date' => $release->postdate,
            'groups_id' => (int) $release->groups_id, 'regex_id' => 0, 'expected_files' => $expected,
            'groups' => array_keys($groups), 'files' => $files, 'metadata_source' => $metadataSource];
        if ($passwords !== []) {
            $manifest['passwords'] = $passwords;
        }
        if ($conflict) {
            $manifest['conflict'] = true;
        }
        if ($verificationMode !== null) {
            $manifest['verification_mode'] = $verificationMode;
        }
        if ($metadataSource === 'import' && is_int($release->categories_id ?? null) && $release->categories_id > 0) {
            $manifest['categories_id'] = $release->categories_id;
        }
        if ($metadataSource === 'import' && ($release->import_name_override ?? false) === true) {
            $manifest['import_name_override'] = true;
        }
        if ($metadataSource === 'import' && is_string($release->original_subject ?? null)) {
            $manifest['original_subject'] = $release->original_subject;
        }

        return $manifest;
    }

    /** Validate the complete metadata boundary before callers make terminal decisions.
     * @return list<string>
     */
    public function passwordMetadata(NzbXmlElements $elements): array
    {
        $elements->assertUnambiguousPasswordMetadata();
        $passwords = [];
        foreach ($elements->metadata() as $metadata) {
            $this->appendPassword($metadata, $passwords);
        }

        return $passwords;
    }

    /** @param list<string> $passwords */
    private function appendPassword(\SimpleXMLElement $metadata, array &$passwords): void
    {
        if ((string) $metadata->attributes()['type'] !== 'password') {
            return;
        }
        foreach (dom_import_simplexml($metadata)->childNodes as $child) {
            if (in_array($child->nodeType, [XML_ELEMENT_NODE, XML_ENTITY_REF_NODE], true)) {
                throw new NzbPasswordMetadataRejected('Invalid NZB password metadata structure.');
            }
        }
        $value = (string) $metadata;
        if (strlen($value) > 4096 || count($passwords) >= 64) {
            throw new NzbPasswordMetadataRejected('NZB password metadata exceeds the supported limit.');
        }
        $passwords[] = $value;
    }

    public static function declaredFileCount(string $subject): int
    {
        $outsideNames = preg_replace('/"[^"\r\n]*"|\'[^\'\r\n]*\'/', '', $subject) ?? '';
        $outsideNames = preg_replace('/\s*\(\d+\/\d+\)\s*$/', '', $outsideNames) ?? '';
        preg_match_all('/(?<!\S)[\[(](\d+)\/(\d+)[\])](?=\s|$)/', $outsideNames, $counts, PREG_SET_ORDER);
        $expected = 0;
        foreach ($counts as $count) {
            $index = (int) $count[1];
            $total = (int) $count[2];
            if ($total > 0 && $index <= $total) {
                $expected = max($expected, $total);
            }
        }

        return $expected;
    }
}
