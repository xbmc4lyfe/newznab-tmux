<?php

declare(strict_types=1);

namespace App\Services\Nzb;

/** Traverse accepted NZB elements by namespace URI, preserving first-container semantics. */
final class NzbXmlElements
{
    public const string CANONICAL_NAMESPACE = 'http://www.newzbin.com/DTD/2003/nzb';

    private readonly string $namespace;

    private readonly bool $legacyFast;

    public function __construct(private readonly \SimpleXMLElement $document)
    {
        $this->namespace = dom_import_simplexml($document)->namespaceURI ?? '';
        if ($document->getName() !== 'nzb' || ! in_array($this->namespace, ['', self::CANONICAL_NAMESPACE], true)) {
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
