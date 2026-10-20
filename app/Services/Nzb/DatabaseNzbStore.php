<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Services\Releases\Fingerprint\DatabaseFingerprintQueue;
use App\Support\ReleaseArchiveNamingEvidence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Published sources and their fingerprint work commit with the release, without filesystem staging. */
final class DatabaseNzbStore
{
    public const MAX_COMPRESSED_BYTES = 33554432;

    public const MAX_DECODED_BYTES = 134217728;

    public static function enabled(): bool
    {
        return config('nntmux.nzb_storage', 'database') === 'database';
    }

    public function read(string $guid, int $maxCompressed = self::MAX_COMPRESSED_BYTES, int $maxDecoded = self::MAX_DECODED_BYTES): string|false
    {
        $row = DB::table('release_nzb_contents')->where('guid', $guid)
            ->where('decoded_bytes', '<=', $maxDecoded)->first(['body', 'source_digest']);
        if ($row === null || ! is_string($row->body) || strlen($row->body) > $maxCompressed) {
            return false;
        }
        $xml = self::decode($row->body, $maxDecoded);
        if ($xml === false || ! hash_equals($row->source_digest, NzbSourceGuard::digest($xml))) {
            throw new RuntimeException('Database NZB integrity check failed.');
        }

        return $xml;
    }

    public function exists(string $guid): bool
    {
        return DB::table('release_nzb_contents')->where('guid', $guid)->exists();
    }

    /** Requires the caller's release lock; lock order is release, content, job. */
    public function putLocked(object $release, string $compressed, ?string $expectedDigest = null, bool $enqueueWork = true): string
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Database NZB installation requires a transaction.');
        }
        $current = DB::table('releases')->where('id', $release->id)->where('guid', $release->guid)->lockForUpdate()->first(['id']);
        if ($current === null) {
            throw new RuntimeException('Release changed before database NZB installation.');
        }
        if (strlen($compressed) > self::MAX_COMPRESSED_BYTES || ($xml = self::decode($compressed, self::MAX_DECODED_BYTES)) === false) {
            throw new RuntimeException('Invalid or oversized compressed NZB.');
        }
        $previous = DB::table('release_nzb_contents')->where('guid', $release->guid)->lockForUpdate()->first();
        if ($expectedDigest !== null && ($previous === null || ! hash_equals($previous->source_digest, $expectedDigest))) {
            throw new RuntimeException('NZB source changed before replacement.');
        }
        $digest = NzbSourceGuard::digest($xml);
        $now = now('UTC');
        $values = ['releases_id' => $release->id, 'body' => $compressed, 'source_digest' => $digest,
            'generation' => $previous !== null && hash_equals($previous->source_digest, $digest) ? $previous->generation : bin2hex(random_bytes(16)),
            'decoded_bytes' => strlen($xml), 'updated_at' => $now];
        if ($previous === null) {
            DB::table('release_nzb_contents')->insert(['guid' => $release->guid, 'created_at' => $now] + $values);
        } else {
            DB::table('release_nzb_contents')->where('guid', $release->guid)->update($values);
        }
        app(ReleaseArchiveNamingEvidence::class)->sourceReplacedLocked($release, $digest);
        if ($enqueueWork) {
            app(DatabaseFingerprintQueue::class)->enqueueLocked($release, $digest);
        }

        return $digest;
    }

    public function put(object $release, string $xml): string
    {
        $body = gzencode($xml);
        if ($body === false) {
            throw new RuntimeException('Cannot compress NZB.');
        }

        return DB::transaction(fn (): string => $this->putLocked($release, $body));
    }

    /** Reject truncated streams, concatenated members and decompression bombs. */
    public static function decode(string $body, int $maxDecoded): string|false
    {
        if ($maxDecoded < 1 || ! str_starts_with($body, "\x1f\x8b")) {
            return false;
        }
        $inflater = @inflate_init(ZLIB_ENCODING_GZIP);
        if ($inflater === false) {
            return false;
        }
        $xml = '';
        $length = strlen($body);
        for ($offset = 0; $offset < $length; $offset += 256) {
            $chunk = @inflate_add($inflater, substr($body, $offset, 256), $offset + 256 >= $length ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
            if ($chunk === false || strlen($xml) + strlen($chunk) > $maxDecoded) {
                return false;
            }
            $xml .= $chunk;
            if (inflate_get_status($inflater) === ZLIB_STREAM_END) {
                break;
            }
        }

        return $xml !== '' && inflate_get_status($inflater) === ZLIB_STREAM_END && inflate_get_read_len($inflater) === $length ? $xml : false;
    }
}
