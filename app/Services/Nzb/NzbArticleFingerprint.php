<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use SimpleXMLElement;

/**
 * Order-independent fingerprint of unique normalized segment Message-IDs.
 *
 * Sorted temporary runs bound memory while streaming. Unique IDs retain the original
 * SHA-256 lane sum and count digest. Unsupported IDs leave identity unknown.
 * Temporary I/O failures defer publication without replacing stored artifacts.
 */
final class NzbArticleFingerprint
{
    public const int MAX_MESSAGE_ID_BYTES = 255;

    private const int BUFFER_IDS = 4096;

    private const int BUFFER_BYTES = 1_048_576;

    private const int MAX_RUN_LEVELS = 32;

    /** @var list<string> */
    private array $buffer = [];

    private int $bufferBytes = 0;

    /** @var array<int, resource> */
    private array $runs = [];

    private bool $failed = false;

    private ?NzbArticleFingerprintTransportFailure $temporaryIoFailure = null;

    private bool $dirty = false;

    private ?string $fingerprint = null;

    private ?\Closure $temporaryStreamFactory;

    /** @param (callable(): (resource|false))|null $temporaryStreamFactory */
    public function __construct(?callable $temporaryStreamFactory = null)
    {
        $this->temporaryStreamFactory = $temporaryStreamFactory === null ? null : \Closure::fromCallable($temporaryStreamFactory);
    }

    private function __clone(): void {}

    public function __destruct()
    {
        $this->closeRuns();
    }

    /**
     * Strip surrounding whitespace, one pair of outer quotes and one pair of angle brackets, the
     * same way {@see NzbService} normalises Message-IDs before writing NZB segments.
     */
    public static function normalizeMessageId(string $messageId): string
    {
        $messageId = trim($messageId);

        if (\strlen($messageId) >= 2
            && (($messageId[0] === '"' && str_ends_with($messageId, '"'))
                || ($messageId[0] === "'" && str_ends_with($messageId, "'")))) {
            $messageId = trim(substr($messageId, 1, -1));
        }

        if (str_starts_with($messageId, '<') && str_ends_with($messageId, '>')) {
            $messageId = substr($messageId, 1, -1);
        }

        return trim($messageId);
    }

    /**
     * The persisted parts schema supports 255-byte IDs. Unsupported input makes
     * article identity unknown; it is never truncated or silently omitted.
     */
    public static function validatedMessageId(string $messageId): ?string
    {
        $messageId = self::normalizeMessageId($messageId);

        return $messageId !== '' && strlen($messageId) <= self::MAX_MESSAGE_ID_BYTES
            && ! preg_match('/[\x00-\x20\x7f]/', $messageId) ? $messageId : null;
    }

    public function add(string $messageId): void
    {
        if ($this->temporaryIoFailure !== null) {
            throw $this->temporaryIoFailure;
        }
        if ($this->failed) {
            return;
        }
        $messageId = self::validatedMessageId($messageId);
        if ($messageId === null) {
            $this->invalidate();

            return;
        }
        $this->dirty = true;
        $this->buffer[] = $messageId;
        $this->bufferBytes += strlen($messageId);
        if (count($this->buffer) >= self::BUFFER_IDS || $this->bufferBytes >= self::BUFFER_BYTES) {
            try {
                $this->flushBuffer();
            } catch (\RuntimeException $exception) {
                $this->invalidate();
                throw $this->temporaryIoFailure = new NzbArticleFingerprintTransportFailure($exception->getMessage(), previous: $exception);
            }
        }
    }

    /** @phpstan-impure */
    public function value(): ?string
    {
        if ($this->temporaryIoFailure !== null) {
            throw $this->temporaryIoFailure;
        }
        if ($this->failed || ! $this->dirty) {
            return $this->fingerprint;
        }
        try {
            $this->flushBuffer();
            $this->consolidateRuns();
            $run = $this->runs[0] ?? null;
            if ($run === null || ! @rewind($run)) {
                throw new \RuntimeException('Cannot rewind article identity run.');
            }
            $lanes = [0, 0, 0, 0, 0, 0, 0, 0];
            $count = 0;
            while (($messageId = $this->readRecord($run)) !== null) {
                /** @var array<int, int> $words */
                $words = unpack('N8', hash('sha256', $messageId, true));
                foreach (array_values($words) as $lane => $word) {
                    $lanes[$lane] = ($lanes[$lane] + $word) & 0xFFFFFFFF;
                }
                $count++;
            }
            $this->fingerprint = $count === 0 ? null : sha1(pack('J', $count).pack('N8', ...$lanes));
            $this->dirty = false;
        } catch (\RuntimeException $exception) {
            $this->invalidate();
            throw $this->temporaryIoFailure = new NzbArticleFingerprintTransportFailure($exception->getMessage(), previous: $exception);
        }

        return $this->fingerprint;
    }

    private function flushBuffer(): void
    {
        if ($this->buffer === []) {
            return;
        }
        sort($this->buffer, SORT_STRING);
        $run = $this->createRun();
        try {
            $previous = null;
            foreach ($this->buffer as $messageId) {
                if ($messageId !== $previous) {
                    $this->writeRecord($run, $messageId);
                    $previous = $messageId;
                }
            }
            $this->buffer = [];
            $this->bufferBytes = 0;
            $level = 0;
            while (isset($this->runs[$level])) {
                $other = $this->runs[$level];
                unset($this->runs[$level]);
                try {
                    $merged = $this->mergeRuns($other, $run);
                } finally {
                    fclose($other);
                }
                fclose($run);
                $run = $merged;
                $level++;
            }
            if ($level >= self::MAX_RUN_LEVELS) {
                throw new \RuntimeException('Article identity run limit exceeded.');
            }
            $this->runs[$level] = $run;
        } catch (\RuntimeException $exception) {
            fclose($run);
            throw $exception;
        }
    }

    private function consolidateRuns(): void
    {
        while (count($this->runs) > 1) {
            $left = array_shift($this->runs);
            $right = array_shift($this->runs);
            try {
                $merged = $this->mergeRuns($left, $right);
            } finally {
                fclose($left);
                fclose($right);
            }
            $this->runs[] = $merged;
        }
        $this->runs = array_values($this->runs);
    }

    /** @return resource */
    private function createRun(): mixed
    {
        $run = $this->temporaryStreamFactory === null ? @tmpfile() : ($this->temporaryStreamFactory)();
        if (! is_resource($run) || get_resource_type($run) !== 'stream') {
            throw new \RuntimeException('Cannot create article identity run.');
        }

        return $run;
    }

    /**
     * @param  resource  $left
     * @param  resource  $right
     * @return resource
     */
    private function mergeRuns(mixed $left, mixed $right): mixed
    {
        $output = $this->createRun();
        try {
            if (! @rewind($left) || ! @rewind($right)) {
                throw new \RuntimeException('Cannot rewind article identity runs.');
            }
            $a = $this->readRecord($left);
            $b = $this->readRecord($right);
            $previous = null;
            while ($a !== null || $b !== null) {
                if ($b === null || ($a !== null && strcmp($a, $b) <= 0)) {
                    $next = $a;
                    $a = $this->readRecord($left);
                } else {
                    $next = $b;
                    $b = $this->readRecord($right);
                }
                if ($next !== $previous) {
                    $this->writeRecord($output, $next);
                    $previous = $next;
                }
            }
        } catch (\RuntimeException $exception) {
            fclose($output);
            throw $exception;
        }

        return $output;
    }

    /** @param resource $run */
    private function writeRecord(mixed $run, string $messageId): void
    {
        $record = pack('N', strlen($messageId)).$messageId;
        $offset = 0;
        while ($offset < strlen($record)) {
            $written = @fwrite($run, substr($record, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Cannot write article identity run.');
            }
            $offset += $written;
        }
    }

    /** @param resource $run */
    private function readRecord(mixed $run): ?string
    {
        $header = @fread($run, 4);
        if ($header === '' && feof($run)) {
            return null;
        }
        if ($header === false || strlen($header) !== 4) {
            throw new \RuntimeException('Cannot read article identity record.');
        }
        $length = unpack('Nlength', $header)['length'];
        if ($length < 1 || $length > self::MAX_MESSAGE_ID_BYTES) {
            throw new \RuntimeException('Invalid article identity record length.');
        }
        $messageId = @fread($run, $length);
        if ($messageId === false || strlen($messageId) !== $length) {
            throw new \RuntimeException('Truncated article identity record.');
        }

        return $messageId;
    }

    private function invalidate(): void
    {
        $this->failed = true;
        $this->fingerprint = null;
        $this->buffer = [];
        $this->bufferBytes = 0;
        $this->closeRuns();
    }

    private function closeRuns(): void
    {
        foreach ($this->runs as $run) {
            fclose($run);
        }
        $this->runs = [];
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
        try {
            $elements = new NzbXmlElements($nzb);
        } catch (\RuntimeException) {
            return null;
        }
        foreach ($elements->files() as $file) {
            foreach ($elements->segments($file) as $segment) {
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
