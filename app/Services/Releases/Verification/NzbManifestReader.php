<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Exceptions\NzbPasswordMetadataRejected;
use App\Services\Nzb\NzbXmlElements;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use RuntimeException;

final class NzbManifestReader
{
    private const string FFI_CDEF = <<<'C'
        int64_t nntmux_nzb_declared_file_count(const char *subject, size_t subject_len);
        ssize_t nntmux_nzb_manifest_read_xml(const char *xml, size_t xml_len, char *out_buf, size_t out_cap);
        C;

    private static bool $useRustFfi = true;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /** @return array<string, mixed> */
    public function read(string $xml, object $release): array
    {
        if (strlen($xml) > 268435456) {
            throw new RuntimeException('NZB exceeds audit metadata budget.');
        }
        if (self::$useRustFfi) {
            $native = self::readNative($xml, $release);
            if ($native !== null) {
                libxml_clear_errors();

                return $native;
            }
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

    /**
     * The Rust reader handles a conservative XML subset. A decline leaves all
     * namespace, password, entity and malformed-input decisions to PHP.
     *
     * @return array<string, mixed>|null
     */
    public static function readNative(string $xml, object $release): ?array
    {
        $length = strlen($xml);
        // Stay below libxml's default 10 MB lexical limits. Larger documents
        // must pass PHP's own validation, even when their XML is well formed.
        if ($length === 0 || $length > 8388608) {
            return null;
        }
        $ffi = RustPipelineLibrary::resolveDomain('nzb_manifest_reader', self::FFI_CDEF);
        if ($ffi === null) {
            return null;
        }
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max($length, 4096), $capacity);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_nzb_manifest_read_xml($xml, $length, $scratch, $capacity);
        if (! is_int($written) || $written === -1) {
            return null;
        }
        if ($written < -1) {
            if ($written < -536870912) {
                return null;
            }
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_nzb_manifest_read_xml($xml, $length, $scratch, $capacity);
        }
        if (! is_int($written) || $written <= 0 || $written > $capacity) {
            return null;
        }
        $parsed = unserialize(FFI::string($scratch, $written), ['allowed_classes' => false]);
        if (! is_array($parsed) || ! isset($parsed['expected_files'], $parsed['groups'], $parsed['files'])) {
            return null;
        }
        $metadataSource = ($release->metadata_source ?? null) === 'import' ? 'import' : 'audit';
        $manifest = ['subject' => $release->searchname, 'fromname' => $release->fromname, 'date' => $release->postdate,
            'groups_id' => (int) $release->groups_id, 'regex_id' => 0, 'expected_files' => $parsed['expected_files'],
            'groups' => $parsed['groups'], 'files' => $parsed['files'], 'metadata_source' => $metadataSource];
        if (isset($parsed['conflict'])) {
            $manifest['conflict'] = $parsed['conflict'];
        }
        if (isset($parsed['verification_mode'])) {
            $manifest['verification_mode'] = $parsed['verification_mode'];
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

    public static function declaredFileCountNative(string $subject): ?int
    {
        $ffi = RustPipelineLibrary::resolveDomain('nzb_manifest_reader', self::FFI_CDEF);
        if ($ffi === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $count = $ffi->nntmux_nzb_declared_file_count($subject, strlen($subject));

        return is_int($count) && $count >= 0 ? $count : null;
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
        // Single-call FFI misses the 1.5x mean/p99 gate; retain PHP here.
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

    /** Wave 51.2 paired benchmarks decide this default; see RustVerificationManifestNntpReaderDeferralsPayloadW51ParityTest. */
    private const bool W51_RUST_FFI_DEFAULT = true;

    public const string W51_FFI_CDEF = <<<'C'
        intptr_t nntmux_w512_nzb_subject_batch(const char *subjects, size_t subjects_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 51 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns a buffer of at least $val words (its capacity
     * through 'cap').
     */
    private static function w51RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w51', self::W51_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w51RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return $out;
            case 'cap':
                return $capacity;
            default:
                return $enabled;
        }
    }

    public static function usesW51RustFfi(): bool
    {
        $enabled = self::w51RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W51_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with summarizeSubjects() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW51RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w51RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w51RustFfiState('set', $previous);
        }
    }

    /**
     * Batch summary of NZB file subjects as read() derives them. Three words per subject: its
     * declaredFileCount(), its trailing `(n/total)` part total (-1 for none), then `repeated |
     * min(strlen, 0x7FFFFFFF) << 1 | crc32(stem) << 32` over the subject without that counter.
     * The largest declared count, the number of subjects with a counter, the repeated count
     * and the XXH64 digest of all earlier words follow.
     *
     * @param  list<string>  $subjects
     * @return list<int>
     */
    public static function summarizeSubjects(array $subjects): array
    {
        return self::usesW51RustFfi() ? self::summarizeSubjectsNative($subjects) : self::summarizeSubjectsPhp($subjects);
    }

    /**
     * @param  list<string>  $subjects
     * @return list<int>
     */
    public static function summarizeSubjectsPhp(array $subjects): array
    {
        $out = $seen = [];
        $expected = $suffixed = $repeated = 0;
        foreach ($subjects as $subject) {
            $declared = self::declaredFileCount($subject);
            $expected = max($expected, $declared);
            $parts = -1;
            $stem = $subject;
            if (preg_match('/\(\d+\/(\d+)\)\s*$/', $subject, $count) === 1) {
                $parts = (int) $count[1];
                $stem = (string) preg_replace('/\s*\(\d+\/\d+\)\s*$/', '', $subject);
                $suffixed++;
            }
            $again = isset($seen[$subject]);
            $seen[$subject] = true;
            $repeated += (int) $again;
            $out[] = $declared;
            $out[] = $parts;
            $out[] = (int) $again | min(\strlen($stem), 0x7FFFFFFF) << 1 | crc32($stem) << 32;
        }
        $out[] = $expected;
        $out[] = $suffixed;
        $out[] = $repeated;
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the subjects are not a list or
     * a subject holds a NUL byte.
     *
     * @param  list<string>  $subjects
     * @return list<int>
     */
    public static function summarizeSubjectsNative(array $subjects): array
    {
        $count = \count($subjects);
        $ffi = $count === 0 ? null : self::w51RustFfiState('ffi');
        if (! $ffi instanceof FFI || ! array_is_list($subjects)) {
            return self::summarizeSubjectsPhp($subjects);
        }
        $joined = implode("\0", $subjects);
        $words = 3 * $count + 4;
        $values = false;
        try {
            $out = self::w51RustFfiState('out', $words);
            if ($out instanceof CData) {
                // @phpstan-ignore method.notFound (Declared by W51_FFI_CDEF.)
                $written = $ffi->nntmux_w512_nzb_subject_batch($joined, \strlen($joined), $count, $out, self::w51RustFfiState('cap'));
                $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;
            }
        } catch (\Throwable) {
            $values = false;
        }

        /** @var list<int>|null $result */
        $result = \is_array($values) ? array_values($values) : null;

        return $result ?? self::summarizeSubjectsPhp($subjects);
    }
}
