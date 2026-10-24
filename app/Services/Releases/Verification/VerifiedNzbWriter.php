<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Services\Nzb\DatabaseNzbStore;
use App\Services\Nzb\NzbService;
use App\Services\Nzb\NzbSourceGuard;
use App\Services\Nzb\NzbSourceInstallation;
use App\Support\NzbPasswordMetadata;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use XMLWriter;

final class VerifiedNzbWriter
{
    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_verified_nzb_document(const char *meta, size_t meta_len, size_t group_count, size_t password_count,
            int64_t inventory, uint32_t flags, const char *files, size_t files_len, size_t file_count, size_t segment_count,
            char *out_buf, size_t out_cap);
        C;

    private static bool $useRustFfi = true;

    public function __construct(private readonly NzbService $nzb) {}

    /** @param array<string, mixed> $manifest */
    public function write(array $manifest, string $guid, ?PublicationTiming $timing = null): string
    {
        return DB::transaction(function () use ($manifest, $guid, $timing): string {
            $release = DB::table('releases')->where('guid', $guid)->lockForUpdate()->first(['id', 'guid']);
            if ($release === null) {
                throw new RuntimeException('Verified NZB installation requires its release row.');
            }
            $receipt = $this->writeWithReceipt($manifest, $guid, $release, $timing);
            DB::afterRollBack(static fn () => $receipt->rollback());
            DB::afterCommit(static fn () => $receipt->close());

            return $receipt->path;
        });
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  string|null  $body  compress(document($manifest)) when the caller rendered it earlier, outside any lock;
     *                             it must come from this same manifest. Null renders and compresses here.
     * @param  int|null  $replaceFor  The candidate whose in-place upgrade replaces the release's source: the NZB is staged and
     *                                swapped in by the receipt's close() after commit (see NzbSourceGuard::install()).
     */
    public function writeWithReceipt(array $manifest, string $guid, object $release, ?PublicationTiming $timing = null, ?string $body = null,
        ?int $replaceFor = null): NzbSourceInstallation
    {
        if (DatabaseNzbStore::enabled()) {
            return (new NzbSourceGuard($this->nzb))->installCompressed($release, $body ?? $this->compress($this->document($manifest)));
        }
        $path = $replaceFor !== null ? $this->upgradePath($guid) : $this->nzb->getNzbPath($guid, createIfNotExist: true);
        $body ??= $this->compress($this->document($manifest));
        $timing?->nextPhase('durable_install');
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $handle = fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot write verified NZB.');
        }
        try {
            try {
                if (fwrite($handle, $body) !== strlen($body) || ! fflush($handle) || ! fsync($handle)) {
                    throw new RuntimeException('Cannot persist verified NZB.');
                }
            } finally {
                fclose($handle);
            }
            $sources = new NzbSourceGuard($this->nzb);
            $receipt = $sources->install($release, $temporary, $path, stageFor: $replaceFor);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return $receipt;
    }

    /** The stored form of a rendered document. */
    public function compress(string $document): string
    {
        $body = gzencode($document);
        if ($body === false) {
            throw new RuntimeException('Cannot compress verified NZB.');
        }

        return $body;
    }

    /** @param array<string, mixed> $manifest */
    public function matches(array $manifest, string $guid): bool
    {
        $contents = $this->nzb->readNzbContents($guid);

        return is_string($contents) && hash_equals($this->document($manifest), $contents);
    }

    /** @param array<string, mixed> $manifest */
    public function document(array $manifest): string
    {
        return $this->documentNative($manifest) ?? $this->documentPhp($manifest);
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /**
     * Rust rendering of documentPhp(): the manifest is packed into two NUL-delimited buffers (see
     * nntmux_verified_nzb_document). Null when the library is unavailable or the input needs PHP's
     * exact XMLWriter or error behavior (control bytes, invalid UTF-8, unexpected types, unparsable date).
     *
     * @param  array<string, mixed>  $manifest
     */
    public function documentNative(array $manifest): ?string
    {
        if (! self::$useRustFfi || ! is_array($files = $manifest['files'] ?? null)) {
            return null;
        }
        $ffi = RustPipelineLibrary::resolveDomain('verified_nzb_document', self::FFI_CDEF);
        if ($ffi === null) {
            return null;
        }
        $packed = '';
        $fileCount = 0;
        $segmentCount = 0;
        foreach ($files as $file) {
            if (! is_array($file) || ! is_array($segments = $file['segments'] ?? null)) {
                return null;
            }
            if ($segments === []) {
                continue;
            }
            $count = count($segments);
            $packed .= $file['subject'].' (1/'.$file['totalparts'].")\0".$count."\0";
            foreach ($segments as $segment) {
                if (! is_string($messageId = $segment['messageid'])) {
                    return null;
                }
                $packed .= $segment['number']."\0".$segment['bytes']."\0".$messageId."\0";
            }
            $fileCount++;
            $segmentCount += $count;
        }
        $inventory = max((int) ($manifest['expected_files'] ?? 0), (int) ($manifest['verified_file_count'] ?? 0), $fileCount);
        $passwords = array_key_exists('passwords', $manifest) ? $manifest['passwords'] : [];
        if (! NzbPasswordMetadata::isValid($passwords)) {
            throw new RuntimeException('Invalid verified NZB password metadata.');
        }
        $meta = "\0\0";
        $groupCount = 0;
        if ($fileCount > 0) {
            if (! is_string($manifest['date'] ?? null) || ($date = strtotime($manifest['date'])) === false || ! is_array($manifest['groups'] ?? null)) {
                return null;
            }
            $meta = (string) $manifest['fromname']."\0".$date."\0";
            foreach ($manifest['groups'] as $group) {
                if (! is_string($group)) {
                    return null;
                }
                $meta .= $group."\0";
                $groupCount++;
            }
        }
        if ($passwords !== []) {
            $meta .= implode("\0", $passwords)."\0";
        }
        $flags = ($manifest['verification_mode'] ?? null) === 'spot_check' ? 1 : 0;
        $metaLength = strlen($meta);
        $packedLength = strlen($packed);
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, $packedLength + $metaLength + 64 * $segmentCount
            + $fileCount * (192 + $metaLength) + 512, $capacity);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_verified_nzb_document($meta, $metaLength, $groupCount, count($passwords), $inventory, $flags,
            $packed, $packedLength, $fileCount, $segmentCount, $scratch, $capacity);
        if (is_int($written) && $written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_verified_nzb_document($meta, $metaLength, $groupCount, count($passwords), $inventory, $flags,
                $packed, $packedLength, $fileCount, $segmentCount, $scratch, $capacity);
        }

        return is_int($written) && $written > 0 ? FFI::string($scratch, $written) : null;
    }

    /** @param array<string, mixed> $manifest */
    public function documentPhp(array $manifest): string
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('nzb');
        $xml->writeAttribute('xmlns', 'http://www.newzbin.com/DTD/2003/nzb');
        $inventory = max((int) ($manifest['expected_files'] ?? 0), (int) ($manifest['verified_file_count'] ?? 0),
            count(array_filter($manifest['files'], static fn (array $file): bool => $file['segments'] !== [])));
        $passwords = array_key_exists('passwords', $manifest) ? $manifest['passwords'] : [];
        if (! NzbPasswordMetadata::isValid($passwords)) {
            throw new RuntimeException('Invalid verified NZB password metadata.');
        }
        if ($inventory > 0 || ($manifest['verification_mode'] ?? null) === 'spot_check' || $passwords !== []) {
            $xml->startElement('head');
            if (($manifest['verification_mode'] ?? null) === 'spot_check') {
                if ($inventory > 0) {
                    $xml->startElement('meta');
                    $xml->writeAttribute('type', 'nntmux:declared-file-count');
                    $xml->text((string) $inventory);
                    $xml->endElement();
                }
                $xml->startElement('meta');
                $xml->writeAttribute('type', 'nntmux:verification-mode');
                $xml->text('spot-check');
                $xml->endElement();
            } elseif ($inventory > 0) {
                $xml->startElement('meta');
                $xml->writeAttribute('type', 'nntmux:verified-file-count');
                $xml->text((string) $inventory);
                $xml->endElement();
            }
            foreach ($passwords as $password) {
                $xml->startElement('meta');
                $xml->writeAttribute('type', 'password');
                $xml->text($password);
                $xml->endElement();
            }
            $xml->endElement();
        }
        foreach ($manifest['files'] as $file) {
            if ($file['segments'] === []) {
                continue;
            }
            $xml->startElement('file');
            $xml->writeAttribute('poster', (string) $manifest['fromname']);
            $xml->writeAttribute('date', (string) strtotime($manifest['date']));
            $xml->writeAttribute('subject', $file['subject'].' (1/'.$file['totalparts'].')');
            $xml->startElement('groups');
            foreach ($manifest['groups'] as $group) {
                $xml->writeElement('group', $group);
            }
            $xml->endElement();
            $xml->startElement('segments');
            foreach ($file['segments'] as $segment) {
                $xml->startElement('segment');
                $xml->writeAttribute('number', (string) $segment['number']);
                $xml->writeAttribute('bytes', (string) $segment['bytes']);
                $xml->text(trim($segment['messageid'], '<>'));
                $xml->endElement();
            }
            $xml->endElement();
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    /**
     * An upgrade installs at the configured path unless readers would still find the release's existing NZB first
     * (as after a split-level change): that file is replaced instead, so the upgrade is never shadowed.
     */
    private function upgradePath(string $guid): string
    {
        $configured = $this->nzb->getNzbPath($guid, createIfNotExist: true);
        foreach ($this->nzb->nzbPathCandidates($guid) as $candidate) {
            if ($candidate === $configured) {
                break;
            }
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $configured;
    }
}
