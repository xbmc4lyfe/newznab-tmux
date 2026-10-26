<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Exceptions\NzbPasswordMetadataRejected;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/** Traverse accepted NZB elements by namespace URI. */
final class NzbXmlElements
{
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_w17_nzb_xml_verification_timing(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);
        C;

    // Paired common/uncommon mean and p99 must all pass before adoption.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /** Reject objects before serialization can invoke user code; bound cycles. */
    private static function nativeValueSupported(mixed $value, int &$remaining, int $depth): bool
    {
        if (--$remaining < 0 || $depth > 24) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::nativeValueSupported($item, $remaining, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_scalar($value);
    }

    /** @param list<mixed> $arguments */
    private static function computeNative(int $operation, array $arguments): mixed
    {
        if (! self::$useRustFfi) {
            return null;
        }
        try {
            $remaining = 100000;
            if (! self::nativeValueSupported($arguments, $remaining, 0)) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $input = serialize($arguments);
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, max(4096, strlen($input) + 512), $capacity);
            if ($output === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w17_nzb_xml_verification_timing($operation, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return unserialize(FFI::string($output, $written), ['allowed_classes' => false]);
        } catch (Throwable) {
            return null;
        }
    }

    public static function validRoot(string $name, string $namespace): bool
    {
        return self::validRootNative($name, $namespace) ?? self::validRootPhp($name, $namespace);
    }

    public static function validRootPhp(string $name, string $namespace): bool
    {
        return $name === 'nzb' && in_array($namespace, ['', self::CANONICAL_NAMESPACE], true);
    }

    public static function validRootNative(string $name, string $namespace): ?bool
    {
        $result = self::computeNative(0, [$name, $namespace]);

        return is_bool($result) ? $result : null;
    }

    public static function validPasswordStructure(int $headCount, bool $hasPassword, bool $hasEntity): bool
    {
        return self::validPasswordStructureNative($headCount, $hasPassword, $hasEntity) ?? self::validPasswordStructurePhp($headCount, $hasPassword, $hasEntity);
    }

    public static function validPasswordStructurePhp(int $headCount, bool $hasPassword, bool $hasEntity): bool
    {
        return ! $hasEntity && ($headCount <= 1 || ! $hasPassword);
    }

    public static function validPasswordStructureNative(int $headCount, bool $hasPassword, bool $hasEntity): ?bool
    {
        $result = self::computeNative(1, [$headCount, $hasPassword, $hasEntity]);

        return is_bool($result) ? $result : null;
    }

    public static function isPasswordMetadata(string $type): bool
    {
        return self::isPasswordMetadataNative($type) ?? self::isPasswordMetadataPhp($type);
    }

    public static function isPasswordMetadataPhp(string $type): bool
    {
        return $type === 'password';
    }

    public static function isPasswordMetadataNative(string $type): ?bool
    {
        $result = self::computeNative(2, [$type]);

        return is_bool($result) ? $result : null;
    }

    public const string CANONICAL_NAMESPACE = 'http://www.newzbin.com/DTD/2003/nzb';

    private readonly string $namespace;

    private readonly bool $legacyFast;

    public function __construct(private readonly \SimpleXMLElement $document)
    {
        $this->namespace = dom_import_simplexml($document)->namespaceURI ?? '';
        if (! self::validRoot($document->getName(), $this->namespace)) {
            throw new \RuntimeException('Invalid NZB.');
        }
        $this->legacyFast = $this->namespace === '' && $document->getDocNamespaces(true) === [];
    }

    /** @return iterable<\SimpleXMLElement> */
    public function files(): iterable
    {
        if ($this->legacyFast) {
            return $this->document->file;
        }

        return $this->namespace === '' ? ($this->document->xpath('./file') ?: []) : $this->document->children($this->namespace)->file;
    }

    /** @return iterable<\SimpleXMLElement> */
    public function groups(\SimpleXMLElement $file): iterable
    {
        return $this->containerChildren($file, 'groups', 'group');
    }

    /** @return iterable<\SimpleXMLElement> */
    public function segments(\SimpleXMLElement $file): iterable
    {
        return $this->containerChildren($file, 'segments', 'segment');
    }

    /** @return iterable<\SimpleXMLElement> */
    public function metadata(): iterable
    {
        return $this->containerChildren($this->document, 'head', 'meta');
    }

    public function assertUnambiguousPasswordMetadata(): void
    {
        $root = dom_import_simplexml($this->document);
        foreach ($root->childNodes as $child) {
            if (! self::validPasswordStructure(0, false, $child->nodeType === XML_ENTITY_REF_NODE)) {
                throw new NzbPasswordMetadataRejected('Invalid NZB password metadata structure.');
            }
            if ($child instanceof \DOMElement && $child->localName === 'head'
                && ($child->namespaceURI ?? '') === $this->namespace) {
                foreach ($child->childNodes as $headChild) {
                    if (! self::validPasswordStructure(0, false, $headChild->nodeType === XML_ENTITY_REF_NODE)) {
                        throw new NzbPasswordMetadataRejected('Invalid NZB password metadata structure.');
                    }
                }
            }
        }
        $heads = $this->namespace === ''
            ? ($this->document->xpath('./head') ?: [])
            : $this->document->children($this->namespace)->head;
        if (count($heads) <= 1) {
            return;
        }
        foreach ($heads as $head) {
            $metadata = $this->namespace === ''
                ? ($head->xpath('./meta') ?: [])
                : $head->children($this->namespace)->meta;
            foreach ($metadata as $meta) {
                if (! self::validPasswordStructure(count($heads), self::isPasswordMetadata((string) $meta->attributes()['type']), false)) {
                    throw new NzbPasswordMetadataRejected('Invalid NZB password metadata structure.');
                }
            }
        }
    }

    /** @return iterable<\SimpleXMLElement> */
    private function containerChildren(\SimpleXMLElement $parent, string $container, string $element): iterable
    {
        if ($this->legacyFast) {
            return $parent->{$container}[0]->{$element} ?? [];
        }
        if ($this->namespace === '') {
            return $parent->xpath('./'.$container.'[1]/'.$element) ?: [];
        }
        $containers = $parent->children($this->namespace)->{$container};

        return isset($containers[0]) ? $containers[0]->children($this->namespace)->{$element} : [];
    }

    /**
     * Default for the Wave 42.2 toggle only; it stays false because the paired benchmark in
     * tests/Unit/RustNzbSourceGuardInstallStagingXmlPar2W42ParityTest.php is below 1.5x.
     */
    public const bool W422_RUST_FFI_DEFAULT = false;

    private static ?bool $useW422RustFfi = null;

    public static function useW422RustFfi(bool $enable = true): void
    {
        self::$useW422RustFfi = $enable;
    }

    public static function usesW422RustFfi(): bool
    {
        return self::$useW422RustFfi ?? self::W422_RUST_FFI_DEFAULT;
    }

    public static function resetW422RustFfi(): void
    {
        self::$useW422RustFfi = null;
    }

    /**
     * Tally NZB metadata types: invalid (not a string), password (isPasswordMetadataPhp()), password_like (`password` in
     * another case), empty or other. `rejected` counts the types validPasswordStructurePhp() refuses under $headCount heads.
     *
     * @param  array<array-key, mixed>  $types
     * @return array{types: int, invalid: int, password: int, password_like: int, empty: int, other: int, rejected: int}
     */
    public static function summarizeMetadataTypes(array $types, int $headCount = 1): array
    {
        return (self::usesW422RustFfi() ? self::summarizeMetadataTypesNative($types, $headCount) : null)
            ?? self::summarizeMetadataTypesPhp($types, $headCount);
    }

    /**
     * @param  array<array-key, mixed>  $types
     * @return array{types: int, invalid: int, password: int, password_like: int, empty: int, other: int, rejected: int}
     */
    public static function summarizeMetadataTypesPhp(array $types, int $headCount = 1): array
    {
        $invalid = $password = $like = $empty = $other = $rejected = 0;
        foreach ($types as $type) {
            if (! is_string($type)) {
                $invalid++;

                continue;
            }
            $isPassword = self::isPasswordMetadataPhp($type);
            if ($isPassword) {
                $password++;
            } elseif (strtolower($type) === 'password') {
                $like++;
            } elseif ($type === '') {
                $empty++;
            } else {
                $other++;
            }
            $rejected += self::validPasswordStructurePhp($headCount, $isPassword, false) ? 0 : 1;
        }

        return ['types' => count($types), 'invalid' => $invalid, 'password' => $password, 'password_like' => $like, 'empty' => $empty,
            'other' => $other, 'rejected' => $rejected];
    }

    /**
     * @param  array<array-key, mixed>  $types
     * @return array{types: int, invalid: int, password: int, password_like: int, empty: int, other: int, rejected: int}|null
     */
    public static function summarizeMetadataTypesNative(array $types, int $headCount = 1): ?array
    {
        $texts = [];
        foreach ($types as $type) {
            $texts[] = is_string($type) ? $type : null;
        }
        $input = NzbSourceGuard::packW422Texts($headCount, $texts);
        $slots = $input === null ? null : NzbSourceGuard::evaluateW422Native(3, $input, 7);
        if ($slots === null) {
            return null;
        }
        [$count, $invalid, $password, $like, $empty, $other, $rejected] = $slots;
        if ($count !== count($types) || min($slots) < 0 || $invalid + $password + $like + $empty + $other !== $count || $rejected > $password) {
            return null;
        }

        return ['types' => $count, 'invalid' => $invalid, 'password' => $password, 'password_like' => $like, 'empty' => $empty,
            'other' => $other, 'rejected' => $rejected];
    }

    // ---- Wave 60.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 10.24x/10.76x, uncommon 9.61x/9.77x); see RustNzbUploadStagingXmlElementsPar2RecoveryResultFingerprintStoreW60ParityTest. */
    public const bool W60_RUST_FFI_DEFAULT = true;

    private const string W60_XML_ELEMENTS_FFI_CDEF = <<<'C'
        intptr_t nntmux_w602_nzb_xml_element_summary_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W60_XML_ELEMENTS_TAIL = 15;

    /**
     * Wave 60 routing, binding and output buffer, kept in function-local statics (no new static properties).
     * Operations: null reads the routing override, 'set' replaces it (returning the previous
     * one), 'ffi' resolves the binding and 'out' returns `[binding, buffer, capacity]` with room
     * for at least $val words (null without the library).
     */
    private static function w60RustFfiState(?string $op = null, mixed $val = null): mixed
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
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w60', self::W60_XML_ELEMENTS_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w60RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW60RustFfi(): bool
    {
        $enabled = self::w60RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W60_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with summarizeXmlElementBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW60RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w60RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w60RustFfiState('set', $previous);
        }
    }

    /** @param  list<int>  $out */
    private static function w60Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /**
     * The words the export wrote, or null when it declined (returning anything but $words).
     *
     * @return list<int>|null
     */
    private static function w60Words(mixed $written, CData $out, int $words): ?array
    {
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : null;
    }

    /** 1 to $max ASCII digits as an integer, otherwise null. */
    private static function w60Digits(string $value, int $max): ?int
    {
        $length = \strlen($value);

        return $length > 0 && $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : null;
    }

    private const string W60_NAME_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_:.-';

    private const string W60_WS = " \t\r\n";

    /** Bytes a segment message-id must not contain: controls, space, angle brackets and DEL. */
    private const string W60_MSGID_STOP = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x20<>\x7f";

    /** Element local name => kind; any other name is kind 9. */
    private const array W60_KINDS = ['file' => 1, 'groups' => 2, 'group' => 3, 'segments' => 4, 'segment' => 5, 'meta' => 6, 'head' => 7, 'nzb' => 8];

    /** Kind => attributes it must carry. */
    private const array W60_REQUIRED = [1 => ['poster', 'date', 'subject'], 5 => ['bytes', 'number'], 6 => ['type']];

    /**
     * Per element string `kind | malformed << 4 | missing << 5 | bad_number << 6 | duplicate << 7 |
     * unclosed << 8 | self_closing << 9 | bad_text << 10 | min(attributes, 7) << 11 |
     * min(number, 0xFFFFF) << 14 | min(bytes, 0x1FFFFFFF) << 34`. The start tag is `<` and a
     * `[A-Za-z0-9_:.-]+` name whose local part (after the last colon) selects the kind: 1 file, 2
     * groups, 3 group, 4 segments, 5 segment, 6 meta, 7 head, 8 nzb, 9 other; then whitespace-led
     * `name = "value"` (or single-quoted) attributes, the first of a repeated name winning, up to
     * `>` or `/>`. Anything else is malformed (kind 0, no other bits). Missing: a file without
     * poster/date/subject, a segment without bytes/number or a meta without type. Bad number: a
     * file date or segment bytes that is not 1-18 digits, or a segment number that is not 1-18
     * digits of at least 1. Unclosed: text after `/>`, or no last `</name>` (trailing whitespace
     * allowed). Bad text: a segment message-id (trimmed content) that is empty or holds controls,
     * spaces, `<`, `>` or DEL, or a group name that is empty or holds whitespace. Number and bytes
     * are set for well-numbered segments only. Then the per-kind counts, the valid count (no flag
     * set), the saturating bytes and largest number of valid segments, the valid password metas
     * and the XXH64 digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function summarizeXmlElementBatchPhp(array $rows): array
    {
        $out = [];
        $kinds = array_fill(0, 10, 0);
        $valid = $bytes = $maxNumber = $passwords = 0;
        foreach ($rows as $row) {
            $element = self::w60Element((string) $row);
            if ($element === null) {
                $kinds[0]++;
                $out[] = 1 << 4;

                continue;
            }
            [$word, $kind, $isValid, $segmentBytes, $number, $password] = $element;
            $kinds[$kind]++;
            if ($isValid) {
                $valid++;
                if ($kind === 5) {
                    $bytes = $bytes > PHP_INT_MAX - $segmentBytes ? PHP_INT_MAX : $bytes + $segmentBytes;
                    $maxNumber = max($maxNumber, $number);
                }
            }
            $passwords += $password ? 1 : 0;
            $out[] = $word;
        }
        array_push($out, ...$kinds);
        array_push($out, $valid, $bytes, $maxNumber, $passwords);
        $out[] = self::w60Digest($out);

        return $out;
    }

    /** @return array{int, int, bool, int, int, bool}|null word, kind, valid, bytes, number and password, or null when malformed */
    private static function w60Element(string $s): ?array
    {
        $n = \strlen($s);
        if ($n < 2 || $s[0] !== '<') {
            return null;
        }
        $length = strspn($s, self::W60_NAME_CHARS, 1);
        if ($length === 0) {
            return null;
        }
        $name = substr($s, 1, $length);
        $colon = strrpos($name, ':');
        $local = $colon === false ? $name : substr($name, $colon + 1);
        if ($local === '') {
            return null;
        }
        $kind = self::W60_KINDS[$local] ?? 9;
        $i = 1 + $length;
        $attributes = [];
        $count = 0;
        $duplicate = false;
        while (true) {
            $ws = strspn($s, self::W60_WS, $i);
            $i += $ws;
            if ($i >= $n) {
                return null;
            }
            if ($s[$i] === '>') {
                $i++;
                $selfClosing = false;

                break;
            }
            if ($s[$i] === '/') {
                if ($i + 1 < $n && $s[$i + 1] === '>') {
                    $i += 2;
                    $selfClosing = true;

                    break;
                }

                return null;
            }
            $attributeLength = $ws === 0 ? 0 : strspn($s, self::W60_NAME_CHARS, $i);
            if ($attributeLength === 0) {
                return null;
            }
            $attribute = substr($s, $i, $attributeLength);
            $i += $attributeLength;
            $i += strspn($s, self::W60_WS, $i);
            if ($i >= $n || $s[$i] !== '=') {
                return null;
            }
            $i++;
            $i += strspn($s, self::W60_WS, $i);
            if ($i >= $n || ($s[$i] !== '"' && $s[$i] !== "'")) {
                return null;
            }
            $end = strpos($s, $s[$i], $i + 1);
            if ($end === false) {
                return null;
            }
            $count++;
            if (isset($attributes[$attribute])) {
                $duplicate = true;
            } else {
                $attributes[$attribute] = substr($s, $i + 1, $end - $i - 1);
            }
            $i = $end + 1;
        }
        if ($selfClosing) {
            $text = '';
            $unclosed = strspn($s, self::W60_WS, $i) !== $n - $i;
        } else {
            $close = $i < $n ? strrpos($s, '</', $i) : false;
            $text = $close === false ? substr($s, $i) : substr($s, $i, $close - $i);
            $unclosed = $close === false || rtrim(substr($s, $close + 2), self::W60_WS) !== $name.'>';
        }
        $text = trim($text, self::W60_WS);
        $badText = match ($kind) {
            5 => $text === '' || strcspn($text, self::W60_MSGID_STOP) !== \strlen($text),
            3 => $text === '' || strcspn($text, self::W60_WS) !== \strlen($text),
            default => false,
        };
        $missing = false;
        foreach (self::W60_REQUIRED[$kind] ?? [] as $required) {
            $missing = $missing || ! isset($attributes[$required]);
        }
        $badNumber = false;
        $number = $bytes = 0;
        if (! $missing && $kind === 1) {
            $badNumber = self::w60Digits($attributes['date'], 18) === null;
        } elseif (! $missing && $kind === 5) {
            $parsedBytes = self::w60Digits($attributes['bytes'], 18);
            $parsedNumber = self::w60Digits($attributes['number'], 18);
            if ($parsedBytes === null || $parsedNumber === null || $parsedNumber < 1) {
                $badNumber = true;
            } else {
                [$bytes, $number] = [$parsedBytes, $parsedNumber];
            }
        }
        $valid = ! ($missing || $badNumber || $duplicate || $unclosed || $badText);
        $word = $kind | (int) $missing << 5 | (int) $badNumber << 6 | (int) $duplicate << 7 | (int) $unclosed << 8 | (int) $selfClosing << 9
            | (int) $badText << 10 | min($count, 7) << 11 | min($number, 0xFFFFF) << 14 | min($bytes, 0x1FFFFFFF) << 34;

        return [$word, $kind, $valid, $bytes, $number, $kind === 6 && $valid && $attributes['type'] === 'password'];
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function summarizeXmlElementBatch(array $rows): array
    {
        return self::usesW60RustFfi() ? self::summarizeXmlElementBatchNative($rows) : self::summarizeXmlElementBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function summarizeXmlElementBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W60_XML_ELEMENTS_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w60RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::summarizeXmlElementBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W60_XML_ELEMENTS_FFI_CDEF.)
            $written = $ffi->nntmux_w602_nzb_xml_element_summary_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }

        return self::w60Words($written, $out, $words) ?? self::summarizeXmlElementBatchPhp($rows);
    }
}
