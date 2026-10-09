<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use SimpleXMLElement;

/**
 * Article identity of an NZB: a hash of its sorted, de-duplicated segment Message-IDs.
 * Two NZBs share a fingerprint only when they reference exactly the same articles.
 */
final class NzbArticleFingerprint
{
    public static function fromXml(SimpleXMLElement $nzb): ?string
    {
        $messageIds = [];

        foreach ($nzb->file as $file) {
            foreach ($file->segments->segment as $segment) {
                $messageId = trim((string) $segment, " \t\n\r\0\x0B<>");
                if ($messageId !== '') {
                    $messageIds[$messageId] = true;
                }
            }
        }

        if ($messageIds === []) {
            return null;
        }

        $messageIds = array_keys($messageIds);
        sort($messageIds, SORT_STRING);

        return sha1(implode("\n", $messageIds));
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
