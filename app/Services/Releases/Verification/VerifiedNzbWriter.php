<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Services\Nzb\NzbService;
use App\Services\Nzb\NzbSourceGuard;
use App\Services\Nzb\NzbSourceInstallation;
use App\Support\NzbPasswordMetadata;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use XMLWriter;

final class VerifiedNzbWriter
{
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
        $path = $this->nzb->getNzbPath($guid, createIfNotExist: true);
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
}
