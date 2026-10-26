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
use FFI\CData;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
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

    // ========================================================================
    // WAVE 59.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of four paired runs cleared every gate (common mean 1.88x, p99 1.81x; uncommon mean 1.69x, p99 1.67x); see RustVerificationLockWorkerLocksVerifiedArticleNzbWriterPublisherW59ParityTest. */
    public const bool W59_RUST_FFI_DEFAULT = true;

    public const string W59_FFI_CDEF = <<<'C'
        ssize_t nntmux_w594_nzb_writer_row_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** int64 words per segmentPlanRowBatch() record. */
    public const int W59_ROW_WORDS = 6;

    /** int64 summary words after the segmentPlanRowBatch() records (none for an empty batch). */
    public const int W59_TRAILER_WORDS = 7;

    /** C0 controls XML 1.0 forbids (tab, LF and CR are allowed). */
    private const string W59_XML_BAD = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0b\x0c\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f";

    private const array W59_ATTRIBUTE = ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "\t" => '&#9;', "\n" => '&#10;', "\r" => '&#13;'];

    private const array W59_TEXT = ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', "\r" => '&#13;'];

    /**
     * Segment plan rows for [file subject, message-id, total parts, segment number, segment
     * bytes] items in document order; consecutive items with the same subject and total form
     * one `<file>`. A subject or trimmed (`trim($id, '<>')`, non-empty) message-id is writable
     * when it is valid UTF-8 without the C0 controls XML forbids. The subject attribute is
     * `subject (1/total)` escaped with `& < > "` and tab, LF, CR character references; the
     * message-id text escapes `& < >` and CR. Segment numbers are valid in 1..1,000,000 and
     * bytes in 1..64 MiB; a segment is in order when it follows the previous valid number of its
     * file (the first is 1).
     *
     * Rows [flags (1 subject writable, 2 message-id writable, 4 subject escaped, 8 message-id
     * escaped, 16 number valid, 32 bytes valid, 64 starts a file, 128 in order, 256 brackets
     * trimmed, 512 segment ok), file index, `<segment bytes="B" number="N">ID</segment>` length,
     * escaped subject attribute length, escaped message-id length, verified bytes (0 unless ok)],
     * then the trailer [ok segments, files, verified bytes, invalid segments, ok segments out of
     * order, document estimate (segment lines plus escaped subject + 64 per file), FNV-1a digest
     * of `flags ^ line << 10`].
     *
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int}>  $items
     * @return list<int>
     */
    public static function segmentPlanRowBatch(array $items): array
    {
        return self::usesW59RustFfi() ? self::segmentPlanRowBatchNative($items) : self::segmentPlanRowBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int}>  $items
     * @return list<int>
     */
    public static function segmentPlanRowBatchPhp(array $items): array
    {
        self::w59Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $summary = [0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        $previousNumber = 0;
        $previousSubject = null;
        $previousTotal = null;
        foreach ($items as [$subject, $id, $total, $number, $bytes]) {
            $subjectLength = \strlen($subject);
            $subjectOk = strcspn($subject, self::W59_XML_BAD) === $subjectLength && mb_check_encoding($subject, 'UTF-8');
            $escapedBody = \strlen(strtr($subject, self::W59_ATTRIBUTE));
            $escapedSubject = $escapedBody + 5 + \strlen((string) $total);
            $trimmed = trim($id, '<>');
            $length = \strlen($trimmed);
            $idOk = $length > 0 && strcspn($trimmed, self::W59_XML_BAD) === $length && mb_check_encoding($trimmed, 'UTF-8');
            $escapedId = \strlen(strtr($trimmed, self::W59_TEXT));
            $numberOk = $number >= 1 && $number <= 1_000_000;
            $bytesOk = $bytes >= 1 && $bytes <= 67_108_864;
            $newFile = $subject !== $previousSubject || $total !== $previousTotal;
            if ($newFile) {
                $summary[1]++;
                $previousNumber = 0;
            }
            $inOrder = $numberOk && $number === $previousNumber + 1;
            $previousNumber = $numberOk ? $number : -1;
            $ok = $subjectOk && $idOk && $numberOk && $bytesOk && $total >= 1;
            $line = 38 + \strlen((string) $bytes) + \strlen((string) $number) + $escapedId;
            $flags = ($subjectOk ? 1 : 0) | ($idOk ? 2 : 0) | ($escapedBody !== $subjectLength ? 4 : 0) | ($escapedId !== $length ? 8 : 0)
                | ($numberOk ? 16 : 0) | ($bytesOk ? 32 : 0) | ($newFile ? 64 : 0) | ($inOrder ? 128 : 0) | ($length !== \strlen($id) ? 256 : 0)
                | ($ok ? 512 : 0);
            array_push($out, $flags, $summary[1] - 1, $line, $escapedSubject, $escapedId, $ok ? $bytes : 0);
            $summary[0] += $ok ? 1 : 0;
            $summary[2] += $ok ? $bytes : 0;
            $summary[3] += $ok ? 0 : 1;
            $summary[4] += $ok && ! $inOrder ? 1 : 0;
            $summary[5] += $line + ($newFile ? $escapedSubject + 64 : 0);
            $value = $flags ^ ($line << 10);
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $previousSubject = $subject;
            $previousTotal = $total;
        }
        $summary[6] = $digest;

        return [...$out, ...$summary];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int}>  $items
     * @return list<int>
     */
    public static function segmentPlanRowBatchNative(array $items): array
    {
        self::w59Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w59Join([array_column($items, 0), array_column($items, 1)], $count);
        $ints = pack('P*', ...array_column($items, 2), ...array_column($items, 3), ...array_column($items, 4));

        return self::w59Call($blob, $twin, $ints, $count, $count * self::W59_ROW_WORDS + self::W59_TRAILER_WORDS)
            ?? self::segmentPlanRowBatchPhp($items);
    }

    public static function usesW59RustFfi(): bool
    {
        $enabled = self::w59RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W59_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W59 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW59RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w59RustFfiState('enabled');
        self::w59RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w59RustFfiState('enabled', $previous);
        }
    }

    /** @param array<array-key, mixed> $items */
    private static function w59Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W59 NZB segment plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 5 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1])
                || ! \is_int($item[2]) || ! \is_int($item[3]) || ! \is_int($item[4])) {
                throw new InvalidArgumentException('W59 NZB segment plan batch items must be [string subject, string message-id, int total parts, int number, int bytes] lists.');
            }
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w59Join(array $columns, int $count): array
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\0", $column);
        }
        $joined = implode("\0", $parts);
        if (substr_count($joined, "\0") === \count($columns) * $count - 1) {
            return [$joined, ''];
        }
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\x01", $column);
        }

        return [$joined, implode("\x01", $parts)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w59Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w59RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w59', self::W59_FFI_CDEF) ?? false;
            self::w59RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w59RustFfiState('out', $out);
                self::w59RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W59_FFI_CDEF.)
            $written = $ffi->nntmux_w594_nzb_writer_row_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w59RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W59 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static: no arguments returns the state, one argument reads a key ('reset'
     * clears everything) and two arguments write a key.
     */
    private static function w59RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }
}
