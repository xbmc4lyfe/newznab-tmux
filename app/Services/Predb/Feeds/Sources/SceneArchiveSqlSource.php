<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\Contracts\CompleteCatalogueSource;
use App\Services\Predb\Feeds\PredbFeedEntry;
use RuntimeException;

/**
 * Manual local reader for the retained nine-column, latin1 2007 PreDB metadata dump.
 * SQL is parsed as bytes, never executed. Only ASCII canonical titles with matching group fields
 * are eligible; music is included. Eligibility counts do not claim all raw rows or verified PREs.
 * A complete validation pass precedes paging. Private hash buckets and statement frames bound
 * memory independently of corpus size; row order is retained without assuming sorted IDs; source dates, categories, sizes, files and nukes stay unknown.
 */
final class SceneArchiveSqlSource implements CompleteCatalogueSource
{
    private const SCHEMA = "create table `predb` ( `id` int(10) not null auto_increment, `section` varchar(20) not null default '', `rlsname` varchar(255) not null default '', `grp` varchar(50) not null default '', `status` decimal(1,0) not null default '0', `nukereason` varchar(255) default null, `ctime` decimal(10,0) not null default '0', `size` int(8) default null, `files` int(6) default null, primary key (`id`), unique key `rlsname` (`rlsname`), key `grp` (`grp`) ) engine=myisam default charset=latin1;";

    private const FRAME_BYTES = 45;

    /** @var resource|null */
    private $input = null;

    /** @var resource|null */
    private $index = null;

    private ?string $workspace = null;

    /** @var array<string, int> */
    private array $fingerprint = [];

    /** @var list<array{offset:int, length:int, hash:string}> */
    private array $protectedFrames = [];

    /** @var array<string, int> */
    private array $rejections = [];

    private bool $prepared = false;

    private int $accepted = 0;

    private int $scratchBytes = 0;

    private int $frameBytes = 0;

    private int $cursorPage = 0;

    /** @var list<PredbFeedEntry> */
    private array $lastPage = [];

    /** @var list<string> */
    private array $pending = [];

    private int $pendingOffset = 0;

    /** @var array{offset:int, length:int, hash:string}|null */
    private ?array $activeFrame = null;

    public function __construct(
        private readonly string $localFilePath,
        private readonly int $pageSize = 100,
        private readonly int $maxFileBytes = 536870912,
        private readonly int $maxStatementBytes = 4194304,
        private readonly int $maxScratchBytes = 268435456,
        private readonly int $maxBucketBytes = 8388608,
        private readonly int $maxFrameIndexBytes = 4194304,
    ) {
        if ($pageSize < 1 || $pageSize > 10000 || $maxFileBytes < 1 || $maxFileBytes > 536870912
            || $maxStatementBytes < 1 || $maxStatementBytes > 4194304
            || $maxScratchBytes < 1 || $maxScratchBytes > 536870912
            || $maxBucketBytes < 32 || $maxBucketBytes > 8388608
            || $maxFrameIndexBytes < self::FRAME_BYTES || $maxFrameIndexBytes > 4194304) {
            throw new RuntimeException('SCENE_ARCHIVE_SQL limits must be positive and within the offline reader bounds.');
        }
    }

    public function __destruct()
    {
        $this->cleanUp();
    }

    public function key(): string
    {
        return 'scene_archive_sql';
    }

    public function acceptedCount(): int
    {
        return $this->accepted;
    }

    /** @return array<string, int> */
    public function rejectionCounts(): array
    {
        return $this->rejections;
    }

    /** @return list<PredbFeedEntry> */
    public function fetch(int $page = 1): array
    {
        if ($page < 1) {
            throw new RuntimeException('SCENE_ARCHIVE_SQL local pages start at one.');
        }
        try {
            $this->prepare();
            $this->assertUnchanged();
            foreach ($this->protectedFrames as $frame) {
                $this->readFrame($frame);
            }
            if ($this->activeFrame !== null) {
                $this->readFrame($this->activeFrame);
            }
            if ($page > (int) ceil($this->accepted / $this->pageSize)) {
                return [];
            }
            if ($page === $this->cursorPage) {
                return $this->lastPage;
            }
            if ($page < $this->cursorPage) {
                $this->resetCursor();
            }
            while ($this->cursorPage < $page) {
                $titles = [];
                while (count($titles) < $this->pageSize) {
                    if ($this->pendingOffset < count($this->pending)) {
                        $titles[] = $this->pending[$this->pendingOffset++];

                        continue;
                    }
                    $frame = $this->nextFrame();
                    if ($frame === null) {
                        break;
                    }
                    $statement = $this->readFrame($frame);
                    $this->activeFrame = $frame;
                    $this->pending = [];
                    $this->pendingOffset = 0;
                    foreach ($this->rows($statement) as $row) {
                        if ($this->eligibilityReason($row) === null) {
                            $this->pending[] = (string) $row[2];
                        }
                    }
                }
                $this->lastPage = array_map(static fn (string $title): PredbFeedEntry => new PredbFeedEntry(title: $title, source: 'scene-archive-sql'), $titles);
                $this->cursorPage++;
            }

            return $this->lastPage;
        } catch (\Throwable $error) {
            $this->cleanUp();
            $this->accepted = 0;
            $this->rejections = [];
            throw $error;
        }
    }

    private function prepare(): void
    {
        if ($this->prepared) {
            return;
        }
        if ($this->localFilePath === '' || preg_match('~^[a-z][a-z0-9+.-]*://~i', $this->localFilePath) === 1
            || ! is_file($this->localFilePath)) {
            throw new RuntimeException('SCENE_ARCHIVE_SQL requires a local regular SQL metadata file.');
        }
        $this->input = @fopen($this->localFilePath, 'rb');
        if ($this->input === false) {
            $this->input = null;
            throw new RuntimeException('SCENE_ARCHIVE_SQL cannot open the local metadata file.');
        }
        $stat = fstat($this->input);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] < 1 || $stat['size'] > $this->maxFileBytes) {
            throw new RuntimeException('SCENE_ARCHIVE_SQL input is not a bounded regular file.');
        }
        $this->fingerprint = $this->fingerprint($stat);
        $workspace = sys_get_temp_dir().'/scene-sql-cache-'.bin2hex(random_bytes(12));
        if (! mkdir($workspace, 0700)) {
            throw new RuntimeException('SCENE_ARCHIVE_SQL cannot create private validation scratch.');
        }
        $this->workspace = $workspace;
        $this->index = fopen($this->workspace.'/frames.bin', 'w+b');
        if ($this->index === false) {
            $this->index = null;
            throw new RuntimeException('SCENE_ARCHIVE_SQL cannot create the bounded frame index.');
        }
        $buckets = [];
        $bucketBytes = [];
        $state = 0;
        $hasRows = false;
        $accepted = 0;
        $rejected = [];
        try {
            while (($frame = $this->statement()) !== null) {
                $normal = strtolower(preg_replace('/\s+/', ' ', trim($frame['statement'])) ?? '');
                $kind = 0;
                $eligible = 0;
                if ($state === 0 && $normal === self::SCHEMA) {
                    $state = 1;
                } elseif ($state === 1 && $normal === '/*!40000 alter table `predb` disable keys */;') {
                    $state = 2;
                } elseif ($state === 2 && $normal === 'lock tables `predb` write;') {
                    $state = 3;
                } elseif ($state === 3 && str_starts_with($frame['statement'], 'INSERT INTO `predb` VALUES ')) {
                    $kind = 1;
                    foreach ($this->rows($frame['statement']) as $row) {
                        $hasRows = true;
                        foreach (["id\0".(string) $row[0], "title\0".strtolower((string) $row[2])] as $identity) {
                            $hash = hash('sha256', $identity, true);
                            $bucket = ord($hash[0]) & 63;
                            if (! isset($buckets[$bucket])) {
                                $buckets[$bucket] = fopen($this->workspace.'/bucket-'.$bucket, 'w+b');
                                if ($buckets[$bucket] === false) {
                                    throw new RuntimeException('SCENE_ARCHIVE_SQL cannot create a duplicate-check bucket.');
                                }
                                $bucketBytes[$bucket] = 0;
                            }
                            $bucketBytes[$bucket] += 32;
                            $this->scratchBytes += 32;
                            if ($bucketBytes[$bucket] > $this->maxBucketBytes || $this->scratchBytes > $this->maxScratchBytes
                                || fwrite($buckets[$bucket], $hash) !== 32) {
                                throw new RuntimeException('SCENE_ARCHIVE_SQL duplicate scratch exceeds its bounds or could not be written.');
                            }
                        }
                        $reason = $this->eligibilityReason($row);
                        if ($reason !== null) {
                            $rejected[$reason] = ($rejected[$reason] ?? 0) + 1;
                        } else {
                            $eligible++;
                            $accepted++;
                        }
                    }
                } elseif ($state === 3 && $normal === 'unlock tables;') {
                    $state = 4;
                } elseif ($state === 4 && $normal === '/*!40000 alter table `predb` enable keys */;') {
                    $state = 5;
                } else {
                    throw new RuntimeException('SCENE_ARCHIVE_SQL contains unsupported schema, encoding, commands or framing.');
                }
                $hash = hash('sha256', $frame['statement'], true);
                if ($kind === 0) {
                    $this->protectedFrames[] = ['offset' => $frame['offset'], 'length' => strlen($frame['statement']), 'hash' => $hash];
                } else {
                    $packed = pack('CNNN', $kind, $frame['offset'], strlen($frame['statement']), $eligible).$hash;
                    $this->scratchBytes += strlen($packed);
                    $this->frameBytes += strlen($packed);
                    if ($this->scratchBytes > $this->maxScratchBytes || $this->frameBytes > $this->maxFrameIndexBytes
                        || fwrite($this->index, $packed) !== self::FRAME_BYTES) {
                        throw new RuntimeException('SCENE_ARCHIVE_SQL frame index exceeds its bounds or could not be written.');
                    }
                }
            }
            if (! in_array($state, [4, 5], true) || ! $hasRows) {
                throw new RuntimeException('SCENE_ARCHIVE_SQL ended before a complete supported dump.');
            }
            foreach ($buckets as $bucket => $stream) {
                rewind($stream);
                $bytes = stream_get_contents($stream, $this->maxBucketBytes + 1);
                if ($bytes === false || strlen($bytes) !== $bucketBytes[$bucket] || strlen($bytes) % 32 !== 0) {
                    throw new RuntimeException('SCENE_ARCHIVE_SQL duplicate bucket is incomplete.');
                }
                $seen = [];
                foreach (str_split($bytes, 32) as $hash) {
                    if (isset($seen[$hash])) {
                        throw new RuntimeException('SCENE_ARCHIVE_SQL contains duplicate IDs or case-folded title identities.');
                    }
                    $seen[$hash] = true;
                }
                fclose($stream);
                unset($buckets[$bucket]);
                unlink($this->workspace.'/bucket-'.$bucket);
            }
            $this->assertUnchanged();
            $this->accepted = $accepted;
            $this->rejections = $rejected;
            $this->prepared = true;
            $this->resetCursor();
        } finally {
            foreach ($buckets as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    /** @return array{offset:int, statement:string}|null */
    private function statement(): ?array
    {
        $buffer = '';
        $quoted = false;
        $escaped = false;
        $offset = 0;
        while (! feof($this->input)) {
            $start = ftell($this->input);
            $line = fgets($this->input, $this->maxStatementBytes + 2);
            if ($line === false) {
                break;
            }
            if ($start + strlen($line) > $this->fingerprint['size']) {
                throw new RuntimeException('SCENE_ARCHIVE_SQL input grew beyond its validated file bound.');
            }
            if ($buffer === '' && (trim($line) === '' || preg_match('/^\s*(?:-- |#)/', $line) === 1)) {
                continue;
            }
            if ($buffer === '') {
                $offset = $start;
            }
            if (strlen($buffer) + strlen($line) > $this->maxStatementBytes || str_contains($line, "\0")) {
                throw new RuntimeException('SCENE_ARCHIVE_SQL statement exceeds its bounds or contains binary bytes.');
            }
            for ($i = 0, $length = strlen($line); $i < $length; $i++) {
                if ($escaped) {
                    $escaped = false;

                    continue;
                }
                $char = $line[$i];
                if ($quoted && $char === '\\') {
                    $escaped = true;
                } elseif ($char === "'") {
                    $quoted = ! $quoted;
                } elseif (! $quoted && $char === ';') {
                    $buffer .= substr($line, 0, $i + 1);
                    if (fseek($this->input, $start + $i + 1) !== 0) {
                        throw new RuntimeException('SCENE_ARCHIVE_SQL cannot preserve statement boundaries.');
                    }

                    return ['offset' => $offset, 'statement' => $buffer];
                }
            }
            $buffer .= $line;
        }
        if (trim($buffer) !== '' || $quoted || $escaped) {
            throw new RuntimeException('SCENE_ARCHIVE_SQL contains a truncated statement.');
        }

        return null;
    }

    /** @return \Generator<int, list<string|null>> */
    private function rows(string $statement): \Generator
    {
        $prefix = 'INSERT INTO `predb` VALUES ';
        if (! str_starts_with($statement, $prefix)) {
            throw new RuntimeException('SCENE_ARCHIVE_SQL returned an invalid INSERT frame.');
        }
        $position = strlen($prefix);
        $length = strlen($statement);
        while (true) {
            if (($statement[$position++] ?? '') !== '(') {
                throw new RuntimeException('SCENE_ARCHIVE_SQL contains an invalid tuple boundary.');
            }
            $row = [];
            do {
                $row[] = $this->scalar($statement, $position);
                $delimiter = $statement[$position++] ?? '';
            } while ($delimiter === ',');
            if ($delimiter !== ')' || count($row) !== 9) {
                throw new RuntimeException('SCENE_ARCHIVE_SQL contains an incomplete nine-column tuple.');
            }
            $this->validateRow($row);
            yield $row;
            if (($statement[$position] ?? '') === ',') {
                $position++;

                continue;
            }
            if (($statement[$position] ?? '') !== ';' || $position + 1 !== $length) {
                throw new RuntimeException('SCENE_ARCHIVE_SQL contains unsupported trailing tuple data.');
            }

            return;
        }
    }

    private function scalar(string $statement, int &$position): ?string
    {
        if (($statement[$position] ?? '') === "'") {
            $position++;
            $value = '';
            $escapes = ['0' => "\0", 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1a", "'" => "'", '"' => '"', '\\' => '\\'];
            while ($position < strlen($statement)) {
                $char = $statement[$position++];
                if ($char === "'") {
                    if (($statement[$position] ?? '') === "'") {
                        $value .= "'";
                        $position++;

                        continue;
                    }

                    return $value;
                }
                if ($char === '\\') {
                    if ($position >= strlen($statement)) {
                        throw new RuntimeException('SCENE_ARCHIVE_SQL contains a truncated string escape.');
                    }
                    $escape = $statement[$position++];
                    if (! isset($escapes[$escape])) {
                        throw new RuntimeException('SCENE_ARCHIVE_SQL contains an unsupported string escape.');
                    }
                    $char = $escapes[$escape];
                }
                $value .= $char;
            }
            throw new RuntimeException('SCENE_ARCHIVE_SQL contains an unterminated string.');
        }
        $end = strcspn($statement, ',)', $position);
        $value = substr($statement, $position, $end);
        $position += $end;
        if ($value === 'NULL') {
            return null;
        }
        if (preg_match('/^-?\d+$/D', $value) !== 1) {
            throw new RuntimeException('SCENE_ARCHIVE_SQL contains an unsupported scalar expression.');
        }

        return $value;
    }

    /** @param list<string|null> $row */
    private function validateRow(array $row): void
    {
        foreach ([1 => 20, 2 => 255, 3 => 50, 5 => 255] as $column => $maximum) {
            if (($row[$column] === null && $column !== 5) || ($row[$column] !== null && strlen($row[$column]) > $maximum)) {
                throw new RuntimeException('SCENE_ARCHIVE_SQL string columns conflict with the declared schema.');
            }
        }
        foreach ([0, 4, 6, 7, 8] as $column) {
            if ($row[$column] === null && in_array($column, [7, 8], true)) {
                continue;
            }
            if ($row[$column] === null || preg_match('/^-?\d+$/D', $row[$column]) !== 1 || strlen($row[$column]) > 11) {
                throw new RuntimeException('SCENE_ARCHIVE_SQL numeric columns conflict with the declared schema.');
            }
        }
        if (preg_match('/^[1-9][0-9]*$/D', (string) $row[0]) !== 1 || (int) $row[0] > 2147483647) {
            throw new RuntimeException('SCENE_ARCHIVE_SQL contains an invalid catalogue ID.');
        }
    }

    /** @param list<string|null> $row */
    private function eligibilityReason(array $row): ?string
    {
        $title = (string) $row[2];
        if (preg_match("/^[A-Za-z0-9_.()&'+,-]+-[A-Za-z0-9_]+$/D", $title) !== 1) {
            return 'noncanonical_title';
        }
        if ($row[3] === '' || ! str_ends_with(strtolower($title), '-'.strtolower((string) $row[3]))) {
            return 'group_conflict';
        }

        return null;
    }

    /** @return array{offset:int, length:int, hash:string}|null */
    private function nextFrame(): ?array
    {
        $bytes = fread($this->index, self::FRAME_BYTES);
        if ($bytes === '' && feof($this->index)) {
            return null;
        }
        if ($bytes === false || strlen($bytes) !== self::FRAME_BYTES) {
            throw new RuntimeException('SCENE_ARCHIVE_SQL private frame index is incomplete.');
        }
        $frame = unpack('Ckind/Noffset/Nlength/Ncount', substr($bytes, 0, 13));

        return ['offset' => $frame['offset'], 'length' => $frame['length'], 'hash' => substr($bytes, 13)];
    }

    /** @param array{offset:int, length:int, hash:string} $frame */
    private function readFrame(array $frame): string
    {
        if (fseek($this->input, $frame['offset']) !== 0) {
            throw new RuntimeException('SCENE_ARCHIVE_SQL cannot seek its validated input.');
        }
        $bytes = stream_get_contents($this->input, $frame['length']);
        if ($bytes === false || strlen($bytes) !== $frame['length'] || ! hash_equals($frame['hash'], hash('sha256', $bytes, true))) {
            throw new RuntimeException('SCENE_ARCHIVE_SQL input changed after complete validation.');
        }

        return $bytes;
    }

    private function resetCursor(): void
    {
        rewind($this->index);
        $this->cursorPage = 0;
        $this->lastPage = [];
        $this->pending = [];
        $this->pendingOffset = 0;
        $this->activeFrame = null;
    }

    private function assertUnchanged(): void
    {
        clearstatcache(true, $this->localFilePath);
        $path = @stat($this->localFilePath);
        $held = fstat($this->input);
        if ($path === false || $held === false || $this->fingerprint($path) !== $this->fingerprint
            || $this->fingerprint($held) !== $this->fingerprint) {
            throw new RuntimeException('SCENE_ARCHIVE_SQL input was replaced or modified.');
        }
    }

    /** @param array<string|int, int> $stat
     * @return array<string, int>
     */
    private function fingerprint(array $stat): array
    {
        return array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']));
    }

    private function cleanUp(): void
    {
        foreach ([$this->input, $this->index] as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $this->input = $this->index = null;
        if ($this->workspace !== null) {
            foreach (glob($this->workspace.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($this->workspace);
        }
        $this->workspace = null;
        $this->prepared = false;
        $this->protectedFrames = [];
        $this->pending = [];
        $this->lastPage = [];
        $this->activeFrame = null;
        $this->scratchBytes = $this->frameBytes = $this->cursorPage = $this->pendingOffset = 0;
    }
}
