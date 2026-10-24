<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Exceptions\NzbPasswordMetadataRejected;
use App\Support\RustPipelineLibrary;
use FFI;

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
        } catch (\Throwable) {
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
}
