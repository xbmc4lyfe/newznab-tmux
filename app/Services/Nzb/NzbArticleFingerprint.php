<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use SimpleXMLElement;

/**
 * Article identity of an NZB: an order-independent multiset hash of its segment Message-IDs.
 *
 * Each Message-ID (angle brackets stripped) contributes its SHA-256 as eight 32-bit lanes that are
 * summed modulo 2^32, plus a count. Accumulation is incremental and constant-memory, so the NZB
 * writer can fingerprint while streaming, and two NZBs share a fingerprint only when they reference
 * the same articles regardless of file or segment order.
 */
final class NzbArticleFingerprint
{
    /** @var list<int> */
    private array $lanes = [0, 0, 0, 0, 0, 0, 0, 0];

    private int $count = 0;

    public function add(string $messageId): void
    {
        $messageId = trim($messageId, " \t\n\r\0\x0B<>");
        if ($messageId === '') {
            return;
        }

        /** @var array<int, int> $words */
        $words = unpack('N8', hash('sha256', $messageId, true));
        foreach (array_values($words) as $lane => $word) {
            $this->lanes[$lane] = ($this->lanes[$lane] + $word) & 0xFFFFFFFF;
        }
        $this->count++;
    }

    public function value(): ?string
    {
        if ($this->count === 0) {
            return null;
        }

        return sha1(pack('J', $this->count).pack('N8', ...$this->lanes));
    }

    /**
     * @param  iterable<string>  $messageIds  Segment Message-IDs, with or without angle brackets
     */
    public static function fromMessageIds(iterable $messageIds): ?string
    {
        $fingerprint = new self;
        foreach ($messageIds as $messageId) {
            $fingerprint->add($messageId);
        }

        return $fingerprint->value();
    }

    public static function fromXml(SimpleXMLElement $nzb): ?string
    {
        $fingerprint = new self;
        foreach ($nzb->file as $file) {
            foreach ($file->segments->segment as $segment) {
                $fingerprint->add((string) $segment);
            }
        }

        return $fingerprint->value();
    }

    public static function fromContents(string $contents): ?string
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $nzb = simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $nzb === false ? null : self::fromXml($nzb);
    }
}
