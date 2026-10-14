<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use FFI\CData;
use FFI\Exception;
use Illuminate\Container\Container;
use SimpleXMLElement;

/**
 * Order-independent fingerprint of unique normalized segment Message-IDs.
 *
 * Rust owns bounded sorted temporary runs and unique-ID SHA-256 lane sums.
 * PHP retains the persisted SHA-1 digest. Unsupported IDs or temporary I/O failures leave
 * identity unknown, so callers retain the upload instead of suppressing it.
 */
final class NzbArticleFingerprint
{
    public const int MAX_MESSAGE_ID_BYTES = 255;

    /** @var array<string, \FFI> */
    private static array $libraries = [];

    private ?\FFI $library = null;

    private ?CData $state = null;

    private ?RustArticleFingerprintWorker $worker = null;

    public function __construct(?string $libraryPath = null, ?string $workerPath = null)
    {
        $root = dirname(__DIR__, 3);
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $configured = class_exists(Container::class, false) && Container::getInstance()->bound('config')
            ? (string) config('yenc.native_library', '') : '';
        $paths = $libraryPath === null ? [
            $root.'/docker/app/nzb-yenc-ffi/target/release/libnzbyenc.'.$suffix,
            $root.'/storage/app/nntmux-native/libnzbyenc.'.$suffix,
            $configured,
            '/usr/local/lib/nntmux/libnzbyenc.so',
        ] : [$libraryPath];
        foreach ($workerPath === null && extension_loaded('FFI') ? $paths : [] as $path) {
            $resolved = realpath($path);
            if ($resolved === false || ! is_file($resolved)) {
                continue;
            }
            $key = getmypid().':'.$resolved;
            try {
                $library = self::$libraries[$key] ??= \FFI::cdef(<<<'C'
                    void *nntmux_article_fingerprint_new(void);
                    int nntmux_article_fingerprint_add(void *state, const char *data, size_t length);
                    int nntmux_article_fingerprint_value(void *state, char *output);
                    void nntmux_article_fingerprint_free(void *state);
                    C, $resolved);
                $state = $library->nntmux_article_fingerprint_new(); // @phpstan-ignore method.notFound
                if (! \FFI::isNull($state)) {
                    $this->library = $library;
                    $this->state = $state;

                    return;
                }
            } catch (Exception) {
                continue;
            }
        }
        if ($libraryPath === null) {
            foreach ($workerPath === null ? [
                $root.'/docker/app/nzb-yenc-ffi/target/release/nntmux-article-fingerprint',
                $root.'/storage/app/nntmux-native/nntmux-article-fingerprint',
                $configured === '' ? '' : dirname($configured).'/nntmux-article-fingerprint',
                '/usr/local/bin/nntmux-article-fingerprint',
            ] : [$workerPath] as $binary) {
                if (is_file($binary) && is_executable($binary)) {
                    try {
                        $this->worker = new RustArticleFingerprintWorker($binary);

                        return;
                    } catch (\RuntimeException) {
                        continue;
                    }
                }
            }
        }
        throw new \RuntimeException('Rust article fingerprint is unavailable. Build it with scripts/build-nntmux-native.sh.');
    }

    private function __clone(): void {}

    public function __destruct()
    {
        if (isset($this->state)) {
            $this->library->nntmux_article_fingerprint_free($this->state); // @phpstan-ignore method.notFound
        }
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
        if ($this->worker !== null) {
            $this->worker->add($messageId);
        } else {
            $this->library->nntmux_article_fingerprint_add($this->state, $messageId, strlen($messageId)); // @phpstan-ignore method.notFound
        }
    }

    /** @phpstan-impure */
    public function value(): ?string
    {
        if ($this->worker !== null) {
            $value = $this->worker->value();

            return $value === null ? null : sha1($value);
        }
        $output = $this->library->new('char[40]');
        $status = $this->library->nntmux_article_fingerprint_value($this->state, $output); // @phpstan-ignore method.notFound

        return $status === 1 ? sha1(\FFI::string($output, 40)) : null;
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
