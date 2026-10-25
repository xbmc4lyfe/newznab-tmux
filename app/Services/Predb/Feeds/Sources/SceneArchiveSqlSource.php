<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\Contracts\CompleteCatalogueSource;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Support\RustPipelineLibrary;
use FFI;
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

    private const NATIVE_CDEF = <<<'C'
        int64_t nntmux_predb_scene_sql_scan_statement(const char *input, size_t len, uint8_t quoted, uint8_t escaped);
        ssize_t nntmux_predb_scene_sql_parse_insert(const char *input, size_t len, uint32_t mode, char *output, size_t cap);
        C;

    /**
     * Wave 45.2 production default for summarizeEligibilityBatch() is PHP: the paired benchmark in
     * tests/Unit/RustPredbFeedsNzedbPredatabaseRssSceneArchiveSasW45ParityTest.php measures 1.72-1.88x mean but
     * 1.37-2.38x p99 on common pages and 1.50-2.02x p99 on uncommon dumps, so p99 does not clear 1.5x in
     * every round.
     */
    public const bool W452_RUST_FFI_DEFAULT = false;

    private static ?bool $w452RustFfiOverride = null;

    private static FFI|false|null $w452RustFfiHandle = null;

    private static FFI|false|null $native = null;

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
        private readonly bool $useNative = true,
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
                    $native = $this->nativeInsert($statement, 1);
                    if ($native !== null) {
                        $this->pending = $native === '' ? [] : explode("\0", substr($native, 0, -1));
                    } else {
                        foreach ($this->rows($statement) as $row) {
                            if (self::eligibilityReason($row) === null) {
                                $this->pending[] = (string) $row[2];
                            }
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
                    $native = $this->nativeInsert($frame['statement'], 0);
                    if ($native !== null) {
                        $counts = unpack('Vrows/Veligible/Vnoncanonical/Vconflict/VfirstNoncanonical/VfirstConflict', substr($native, 0, 24));
                        $hasRows = $hasRows || $counts['rows'] > 0;
                        $eligible = $counts['eligible'];
                        $accepted += $eligible;
                        $reasons = $counts['firstNoncanonical'] !== 0
                            && ($counts['firstConflict'] === 0 || $counts['firstNoncanonical'] < $counts['firstConflict'])
                            ? ['noncanonical_title' => $counts['noncanonical'], 'group_conflict' => $counts['conflict']]
                            : ['group_conflict' => $counts['conflict'], 'noncanonical_title' => $counts['noncanonical']];
                        foreach ($reasons as $reason => $count) {
                            if ($count > 0) {
                                $rejected[$reason] = ($rejected[$reason] ?? 0) + $count;
                            }
                        }
                        // Group writes within one bounded statement, retaining the same per-hash
                        // bucket and total scratch limits as the streaming PHP implementation.
                        $writes = [];
                        for ($position = 24, $length = strlen($native); $position < $length; $position += 32) {
                            $hash = substr($native, $position, 32);
                            $bucket = ord($hash[0]) & 63;
                            $bucketBytes[$bucket] = ($bucketBytes[$bucket] ?? 0) + 32;
                            $this->scratchBytes += 32;
                            if ($bucketBytes[$bucket] > $this->maxBucketBytes || $this->scratchBytes > $this->maxScratchBytes) {
                                throw new RuntimeException('SCENE_ARCHIVE_SQL duplicate scratch exceeds its bounds or could not be written.');
                            }
                            $writes[$bucket] = ($writes[$bucket] ?? '').$hash;
                        }
                        foreach ($writes as $bucket => $bytes) {
                            if (! isset($buckets[$bucket])) {
                                $buckets[$bucket] = fopen($this->workspace.'/bucket-'.$bucket, 'w+b');
                                if ($buckets[$bucket] === false) {
                                    throw new RuntimeException('SCENE_ARCHIVE_SQL cannot create a duplicate-check bucket.');
                                }
                            }
                            if (fwrite($buckets[$bucket], $bytes) !== strlen($bytes)) {
                                throw new RuntimeException('SCENE_ARCHIVE_SQL duplicate scratch exceeds its bounds or could not be written.');
                            }
                        }
                    } else {
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
                            $reason = self::eligibilityReason($row);
                            if ($reason !== null) {
                                $rejected[$reason] = ($rejected[$reason] ?? 0) + 1;
                            } else {
                                $eligible++;
                                $accepted++;
                            }
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
            $ffi = $this->useNative ? (self::$native ??= RustPipelineLibrary::resolveDomain('predb_sql_html', self::NATIVE_CDEF) ?? false) : false;
            if ($ffi !== false) {
                // @phpstan-ignore method.notFound
                $boundary = $ffi->nntmux_predb_scene_sql_scan_statement($line, strlen($line), (int) $quoted, (int) $escaped);
                if ($boundary >= 0) {
                    $buffer .= substr($line, 0, $boundary + 1);
                    if (fseek($this->input, $start + $boundary + 1) !== 0) {
                        throw new RuntimeException('SCENE_ARCHIVE_SQL cannot preserve statement boundaries.');
                    }

                    return ['offset' => $offset, 'statement' => $buffer];
                }
                if ($boundary >= -4) {
                    $quoted = ((-$boundary - 1) & 1) !== 0;
                    $escaped = ((-$boundary - 1) & 2) !== 0;
                } else {
                    throw new RuntimeException('SCENE_ARCHIVE_SQL statement exceeds its bounds or contains binary bytes.');
                }
            } else {
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
            }
            $buffer .= $line;
        }
        if (trim($buffer) !== '' || $quoted || $escaped) {
            throw new RuntimeException('SCENE_ARCHIVE_SQL contains a truncated statement.');
        }

        return null;
    }

    /** Raw validated protocol, or null to retain PHP error and resource-limit precedence. */
    private function nativeInsert(string $statement, int $mode): ?string
    {
        if (! $this->useNative) {
            return null;
        }
        $ffi = self::$native ??= RustPipelineLibrary::resolveDomain('predb_sql_html', self::NATIVE_CDEF) ?? false;
        if ($ffi === false) {
            return null;
        }
        // Every nine-field tuple occupies at least 19 input bytes, even before
        // schema validation. Four times the statement bounds both hash and title output.
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, strlen($statement) * ($mode === 0 ? 4 : 1) + 24, $capacity);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_predb_scene_sql_parse_insert($statement, strlen($statement), $mode, $scratch, $capacity);
        if ($written < 0) {
            return null;
        }

        return $written === 0 ? '' : FFI::string($scratch, $written);
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

    /** @param array<int, string|null> $row */
    private static function eligibilityReason(array $row): ?string
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

    /** Force the Wave 45.2 helper onto Rust (true) or PHP (false); null restores W452_RUST_FFI_DEFAULT. */
    public static function useW452RustFfi(?bool $enable = true): void
    {
        self::$w452RustFfiOverride = $enable;
    }

    public static function usesW452RustFfi(): bool
    {
        return self::$w452RustFfiOverride ?? self::W452_RUST_FFI_DEFAULT;
    }

    public static function resetW452RustFfi(): void
    {
        self::$w452RustFfiOverride = null;
        self::$w452RustFfiHandle = null;
    }

    /**
     * Summarize parsed INSERT rows as eligibilityReason() admits them, reading the title (index 2) and group
     * (index 3): non-arrays and rows whose title or group is neither a string nor null are invalid; eligible
     * titles also count their distinct strtolower() identities.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array{total: int, invalid: int, noncanonical_title: int, group_conflict: int, eligible: int, distinct: int}
     */
    public static function summarizeEligibilityBatch(array $rows): array
    {
        return (self::usesW452RustFfi() ? self::summarizeEligibilityBatchNative($rows) : null)
            ?? self::summarizeEligibilityBatchPhp($rows);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array{total: int, invalid: int, noncanonical_title: int, group_conflict: int, eligible: int, distinct: int}
     */
    public static function summarizeEligibilityBatchPhp(array $rows): array
    {
        $summary = ['total' => \count($rows), 'invalid' => 0, 'noncanonical_title' => 0, 'group_conflict' => 0, 'eligible' => 0, 'distinct' => 0];
        $titles = [];
        foreach ($rows as $row) {
            $title = \is_array($row) ? ($row[2] ?? null) : null;
            $group = \is_array($row) ? ($row[3] ?? null) : null;
            if (! \is_array($row) || ($title !== null && ! \is_string($title)) || ($group !== null && ! \is_string($group))) {
                $summary['invalid']++;

                continue;
            }
            $reason = self::eligibilityReason([2 => $title, 3 => $group]);
            if ($reason !== null) {
                $summary[$reason]++;

                continue;
            }
            $summary['eligible']++;
            $titles[strtolower((string) $title)] = true;
        }
        $summary['distinct'] = \count($titles);

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array{total: int, invalid: int, noncanonical_title: int, group_conflict: int, eligible: int, distinct: int}|null
     */
    public static function summarizeEligibilityBatchNative(array $rows): ?array
    {
        // String titles and groups go NUL-delimited; any other row or field needs the length table.
        $fields = [];
        foreach ($rows as $row) {
            if (! \is_array($row) || ! \is_string($row[2] ?? null) || ! \is_string($row[3] ?? null)) {
                $fields = [];

                break;
            }
            $fields[] = $row[2];
            $fields[] = $row[3];
        }
        $blob = null;
        if ($fields !== []) {
            $blob = implode("\0", $fields);
            if (substr_count($blob, "\0") !== \count($fields) - 1) {
                $blob = null;
            }
        }
        $lens = '';
        $operation = 3 | PredatabaseSource::W452_DELIMITED;
        if ($blob === null) {
            $lengths = [];
            $parts = [];
            foreach ($rows as $row) {
                $title = \is_array($row) ? ($row[2] ?? null) : null;
                $group = \is_array($row) ? ($row[3] ?? null) : null;
                if (! \is_array($row) || ($title !== null && ! \is_string($title)) || ($group !== null && ! \is_string($group))) {
                    array_push($lengths, PredatabaseSource::W452_INVALID, PredatabaseSource::W452_INVALID);

                    continue;
                }
                $lengths[] = $title === null ? PredatabaseSource::W452_NULL : \strlen($title);
                $lengths[] = $group === null ? PredatabaseSource::W452_NULL : \strlen($group);
                if ($title !== null) {
                    $parts[] = $title;
                }
                if ($group !== null) {
                    $parts[] = $group;
                }
            }
            $blob = implode('', $parts);
            $lens = pack('V*', ...$lengths);
            $operation = 3;
        }
        if (\strlen($blob) > PredatabaseSource::W452_MAX_INPUT || \strlen($lens) > PredatabaseSource::W452_MAX_INPUT) {
            return null;
        }
        $values = PredatabaseSource::callW452Native(self::w452RustFfi(), $operation, 0, $blob, $lens, 6);
        if ($values === null) {
            return null;
        }
        [$total, $invalid, $noncanonical, $conflict, $eligible, $distinct] = $values;
        if ($total !== \count($rows) || min($values) < 0 || $invalid + $noncanonical + $conflict + $eligible !== $total
            || $distinct > $eligible || ($distinct > 0) !== ($eligible > 0)) {
            return null;
        }

        return ['total' => $total, 'invalid' => $invalid, 'noncanonical_title' => $noncanonical, 'group_conflict' => $conflict,
            'eligible' => $eligible, 'distinct' => $distinct];
    }

    private static function w452RustFfi(): ?FFI
    {
        self::$w452RustFfiHandle ??= RustPipelineLibrary::resolveDomain(PredatabaseSource::W452_FFI_DOMAIN, PredatabaseSource::W452_FFI_CDEF) ?? false;

        return self::$w452RustFfiHandle === false ? null : self::$w452RustFfiHandle;
    }
}
