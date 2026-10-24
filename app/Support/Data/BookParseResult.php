<?php

declare(strict_types=1);

namespace App\Support\Data;

use App\Support\RustPipelineLibrary;
use FFI;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Throwable;

#[TypeScript]
final class BookParseResult extends Data
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_book_parse_result_from_serialized(const char *ser, size_t ser_len, int64_t *out_year, int32_t *out_flags, char *out_buf, size_t out_cap);
        ssize_t nntmux_book_parse_result_inspect(const char *title, size_t title_len, uint32_t has_author, const char *author, size_t author_len, uint32_t has_isbn, const char *isbn, size_t isbn_len, uint32_t has_year, int64_t year, uint32_t has_format, const char *format, size_t format_len, uint32_t is_junk, int32_t *out_flags, int32_t *out_completeness, char *out_query, size_t query_cap);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustTvIgdbSteamMetadataParityTest). */
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    public function __construct(
        public string $rawName,
        public string $title,
        public ?string $author = null,
        public ?string $isbn = null,
        public ?int $year = null,
        public ?string $format = null,
        public bool $isJunk = false,
        public bool $isMagazine = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return self::usesRustFfi()
            ? self::fromArrayNative($data)
            : self::fromArrayPhp($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArrayNative(array $data): self
    {
        if (($ffi = self::ffi()) === null) {
            return self::fromArrayPhp($data);
        }
        try {
            $subset = array_intersect_key($data, [
                'rawName' => true,
                'raw_name' => true,
                'title' => true,
                'author' => true,
                'isbn' => true,
                'year' => true,
                'format' => true,
                'isJunk' => true,
                'is_junk' => true,
                'isMagazine' => true,
                'is_magazine' => true,
            ]);
            foreach ($subset as $val) {
                if (\is_object($val) || \is_array($val)) {
                    return self::fromArrayPhp($data);
                }
            }
            $ser = serialize($subset);
            $serLen = \strlen($ser);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, $serLen + 64), $capacity);
            if ($buffer === null) {
                return self::fromArrayPhp($data);
            }
            $outYear = $ffi->new('int64_t[1]');
            $outFlags = $ffi->new('int32_t[1]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_book_parse_result_from_serialized($ser, $serLen, $outYear, $outFlags, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::fromArrayPhp($data);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_book_parse_result_from_serialized($ser, $serLen, $outYear, $outFlags, $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 4 || $written > $capacity) {
                return self::fromArrayPhp($data);
            }
            $parts = explode("\0", FFI::string($buffer, $written), 5);
            if (\count($parts) !== 5) {
                return self::fromArrayPhp($data);
            }
            $flags = (int) $outFlags[0];

            return new self(
                rawName: $parts[0],
                title: $parts[1],
                author: ($flags & 1) !== 0 ? $parts[2] : null,
                isbn: ($flags & 2) !== 0 ? $parts[3] : null,
                year: ($flags & 4) !== 0 ? (int) $outYear[0] : null,
                format: ($flags & 8) !== 0 ? $parts[4] : null,
                isJunk: ($flags & 16) !== 0,
                isMagazine: ($flags & 32) !== 0,
            );
        } catch (Throwable) {
            return self::fromArrayPhp($data);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArrayPhp(array $data): self
    {
        $rawName = $data['rawName'] ?? $data['raw_name'] ?? '';
        $title = $data['title'] ?? '';
        $author = $data['author'] ?? null;
        $isbn = $data['isbn'] ?? null;
        $year = $data['year'] ?? null;
        $format = $data['format'] ?? null;
        $isJunk = $data['isJunk'] ?? $data['is_junk'] ?? false;
        $isMagazine = $data['isMagazine'] ?? $data['is_magazine'] ?? false;

        $yearInt = null;
        if (\is_int($year)) {
            $yearInt = $year;
        } elseif (\is_string($year)) {
            $trimmedYear = trim($year);
            if ($trimmedYear !== '' && preg_match('/^[+-]?\d+$/', $trimmedYear) === 1) {
                $yearInt = (int) $trimmedYear;
            }
        }

        return new self(
            rawName: \is_string($rawName) ? $rawName : (string) $rawName,
            title: \is_string($title) ? $title : (string) $title,
            author: \is_string($author) && $author !== '' ? $author : null,
            isbn: \is_string($isbn) && $isbn !== '' ? $isbn : null,
            year: $yearInt,
            format: \is_string($format) && $format !== '' ? $format : null,
            isJunk: (bool) $isJunk,
            isMagazine: (bool) $isMagazine,
        );
    }

    /**
     * @return array{rawName: string, title: string, author: ?string, isbn: ?string, year: ?int, format: ?string, isJunk: bool, isMagazine: bool}
     */
    public function toArray(): array
    {
        return self::usesRustFfi() ? $this->toArrayNative() : $this->toArrayPhp();
    }

    /**
     * @return array{rawName: string, title: string, author: ?string, isbn: ?string, year: ?int, format: ?string, isJunk: bool, isMagazine: bool}
     */
    public function toArrayNative(): array
    {
        $inspected = $this->inspectNative();
        if ($inspected === null) {
            return $this->toArrayPhp();
        }

        return $this->toArrayPhp();
    }

    /**
     * @return array{rawName: string, title: string, author: ?string, isbn: ?string, year: ?int, format: ?string, isJunk: bool, isMagazine: bool}
     */
    public function toArrayPhp(): array
    {
        return [
            'rawName' => $this->rawName,
            'title' => $this->title,
            'author' => $this->author,
            'isbn' => $this->isbn,
            'year' => $this->year,
            'format' => $this->format,
            'isJunk' => $this->isJunk,
            'isMagazine' => $this->isMagazine,
        ];
    }

    public function hasAuthor(): bool
    {
        return self::usesRustFfi() ? $this->hasAuthorNative() : $this->hasAuthorPhp();
    }

    public function hasAuthorNative(): bool
    {
        $inspected = $this->inspectNative();

        return $inspected !== null ? ($inspected['flags'] & 1) !== 0 : $this->hasAuthorPhp();
    }

    public function hasAuthorPhp(): bool
    {
        return $this->author !== null && $this->author !== '';
    }

    public function hasIsbn(): bool
    {
        return self::usesRustFfi() ? $this->hasIsbnNative() : $this->hasIsbnPhp();
    }

    public function hasIsbnNative(): bool
    {
        $inspected = $this->inspectNative();

        return $inspected !== null ? ($inspected['flags'] & 2) !== 0 : $this->hasIsbnPhp();
    }

    public function hasIsbnPhp(): bool
    {
        return $this->isbn !== null && $this->isbn !== '';
    }

    public function hasTitleAndAuthor(): bool
    {
        return self::usesRustFfi() ? $this->hasTitleAndAuthorNative() : $this->hasTitleAndAuthorPhp();
    }

    public function hasTitleAndAuthorNative(): bool
    {
        $inspected = $this->inspectNative();

        return $inspected !== null ? ($inspected['flags'] & 4) !== 0 : $this->hasTitleAndAuthorPhp();
    }

    public function hasTitleAndAuthorPhp(): bool
    {
        return trim($this->title) !== '' && $this->author !== null && trim($this->author) !== '';
    }

    public function isComplete(): bool
    {
        return self::usesRustFfi() ? $this->isCompleteNative() : $this->isCompletePhp();
    }

    public function isCompleteNative(): bool
    {
        $inspected = $this->inspectNative();

        return $inspected !== null ? ($inspected['flags'] & 8) !== 0 : $this->isCompletePhp();
    }

    public function isCompletePhp(): bool
    {
        return ! $this->isJunk
            && trim($this->title) !== ''
            && (($this->author !== null && trim($this->author) !== '')
                || ($this->isbn !== null && trim($this->isbn) !== ''));
    }

    public function completenessScore(): int
    {
        return self::usesRustFfi() ? $this->completenessScoreNative() : $this->completenessScorePhp();
    }

    public function completenessScoreNative(): int
    {
        $inspected = $this->inspectNative();

        return $inspected !== null ? $inspected['completeness'] : $this->completenessScorePhp();
    }

    public function completenessScorePhp(): int
    {
        $score = 0;
        if (trim($this->title) !== '') {
            $score++;
        }
        if ($this->author !== null && trim($this->author) !== '') {
            $score++;
        }
        if ($this->isbn !== null && trim($this->isbn) !== '') {
            $score++;
        }
        if ($this->year !== null && $this->year > 0) {
            $score++;
        }
        if ($this->format !== null && trim($this->format) !== '') {
            $score++;
        }

        return $score;
    }

    public function searchQuery(): string
    {
        return self::usesRustFfi() ? $this->searchQueryNative() : $this->searchQueryPhp();
    }

    public function searchQueryNative(): string
    {
        $inspected = $this->inspectNative();

        return $inspected !== null ? $inspected['query'] : $this->searchQueryPhp();
    }

    public function searchQueryPhp(): string
    {
        return trim(($this->author ?? '').' '.$this->title);
    }

    /**
     * @return array{flags: int, completeness: int, query: string}|null
     */
    private function inspectNative(): ?array
    {
        if (($ffi = self::ffi()) === null) {
            return null;
        }
        $titleLen = \strlen($this->title);
        $author = $this->author ?? '';
        $authorLen = \strlen($author);
        $isbn = $this->isbn ?? '';
        $isbnLen = \strlen($isbn);
        $format = $this->format ?? '';
        $formatLen = \strlen($format);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(64, $titleLen + $authorLen + 16), $capacity);
        if ($buffer === null) {
            return null;
        }
        try {
            $outFlags = $ffi->new('int32_t[1]');
            $outCompleteness = $ffi->new('int32_t[1]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_book_parse_result_inspect(
                $this->title,
                $titleLen,
                $this->author !== null ? 1 : 0,
                $author,
                $authorLen,
                $this->isbn !== null ? 1 : 0,
                $isbn,
                $isbnLen,
                $this->year !== null ? 1 : 0,
                $this->year ?? 0,
                $this->format !== null ? 1 : 0,
                $format,
                $formatLen,
                $this->isJunk ? 1 : 0,
                $outFlags,
                $outCompleteness,
                $buffer,
                $capacity,
            );
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_book_parse_result_inspect(
                    $this->title,
                    $titleLen,
                    $this->author !== null ? 1 : 0,
                    $author,
                    $authorLen,
                    $this->isbn !== null ? 1 : 0,
                    $isbn,
                    $isbnLen,
                    $this->year !== null ? 1 : 0,
                    $this->year ?? 0,
                    $this->format !== null ? 1 : 0,
                    $format,
                    $formatLen,
                    $this->isJunk ? 1 : 0,
                    $outFlags,
                    $outCompleteness,
                    $buffer,
                    $capacity,
                );
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return [
                'flags' => (int) $outFlags[0],
                'completeness' => (int) $outCompleteness[0],
                'query' => $written === 0 ? '' : FFI::string($buffer, $written),
            ];
        } catch (Throwable) {
            return null;
        }
    }
}
