<?php

declare(strict_types=1);

namespace App\Services\Categorization;

use App\Models\Category;
use App\Models\UsenetGroup;
use App\Services\Categorization\Pipes\AbstractCategorizationPipe;
use App\Services\Categorization\Pipes\CategorizationPassable;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\NameFixing\Extractors\ObfuscatedSubjectExtractor;
use App\Services\NameFixing\NzbSplitUnwrapper;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pipeline-based categorization service using Laravel Pipeline.
 *
 * This service uses Laravel's Pipeline to orchestrate multiple categorizers
 * to determine the best category for a release. Each categorizer (pipe) is
 * responsible for a specific category domain and returns a result with a
 * confidence score.
 */
class CategorizationPipeline
{
    /**
     * Wave 41.3 production default is PHP: the paired benchmark in
     * tests/Unit/RustCategorizationPipelineResultMusicPcTvW41ParityTest.php measures 1.69-1.72x mean
     * but only 1.38-1.74x p99 on the common batch (3.89-3.95x mean / 2.99-3.58x p99 uncommon), so the
     * common p99 misses the 1.5x adoption threshold and Rust stays behind useW413RustFfi(true).
     * Named W413 so it stays distinct from other Rust FFI toggles.
     */
    public const bool W413_RUST_FFI_DEFAULT = false;

    public const string W413_FFI_CDEF = <<<'C'
        ssize_t nntmux_w413_release_name_shape_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Trimmed release names longer than this many bytes count as overlong. */
    public const int W413_MAX_RELEASE_NAME_LENGTH = 255;

    /** Bytes allowed in a trailing "-GROUP" suffix. */
    private const string W413_GROUP_SUFFIX_BYTES = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /** Exactly 9 0x1F-separated decimals of 1-19 digits. */
    private const string W413_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){8}\d{1,19}\z/';

    private static ?bool $w413RustFfiOverride = null;

    private static FFI|false|null $w413RustFfiHandle = null;

    private const string NATIVE_CDEF = <<<'CDEF'
        int64_t nntmux_categorize_pipeline(const char *name, size_t name_len, const char *group, size_t group_len, const char *poster, size_t poster_len, uint32_t flags);
CDEF;

    private static FFI|false|null $nativeFfi = null;

    protected bool $defaultPipesUnmodified = false;

    /**
     * @var Collection<AbstractCategorizationPipe>
     */
    protected Collection $pipes; // @phpstan-ignore missingType.generics

    protected ?bool $categorizeForeign = null;

    protected ?bool $catWebDL = null;

    protected NzbSplitUnwrapper $nzbSplitUnwrapper;

    protected ObfuscatedSubjectExtractor $obfuscatedSubjectExtractor;

    /**
     * @param  iterable<AbstractCategorizationPipe>  $pipes
     */
    public function __construct(
        iterable $pipes = [],
        ?NzbSplitUnwrapper $nzbSplitUnwrapper = null,
        ?ObfuscatedSubjectExtractor $obfuscatedSubjectExtractor = null
    ) {
        $this->pipes = collect($pipes)
            ->sortBy(fn (AbstractCategorizationPipe $p) => $p->getPriority());

        $this->nzbSplitUnwrapper = $nzbSplitUnwrapper ?? new NzbSplitUnwrapper;
        $this->obfuscatedSubjectExtractor = $obfuscatedSubjectExtractor ?? new ObfuscatedSubjectExtractor;
    }

    /**
     * Register a categorizer pipe in the pipeline.
     */
    public function addCategorizer(AbstractCategorizationPipe $pipe): self
    {
        $this->defaultPipesUnmodified = false;
        $this->pipes->push($pipe);
        $this->pipes = $this->pipes->sortBy(fn (AbstractCategorizationPipe $p) => $p->getPriority());

        return $this;
    }

    public function addPipe(AbstractCategorizationPipe $pipe): self
    {
        return $this->addCategorizer($pipe);
    }

    /**
     * Determine the category for a release using Laravel Pipeline.
     *
     * @param  int|string  $groupId  The usenet group ID
     * @param  string  $releaseName  The name of the release
     * @param  string|null  $poster  The poster name
     * @param  bool  $debug  Whether to include debug information
     * @return array<string, mixed> The categorization result
     */
    public function categorize(
        int|string $groupId,
        string $releaseName,
        ?string $poster = '',
        bool $debug = false
    ): array {
        $releaseName = $this->nzbSplitUnwrapper->unwrap($releaseName) ?? $releaseName;

        // Normalizing dots to spaces would erase the explicit OF. site prefix.
        if (! (new ReleaseContext($releaseName, $groupId))->hasOnlyFansMarker()) {
            $releaseName = $this->obfuscatedSubjectExtractor->extract($releaseName) ?? $releaseName;
        }

        $groupName = UsenetGroup::whereId($groupId)->value('name') ?? '';

        if ($this->categorizeForeign === null || $this->catWebDL === null) {
            $ingestion = app(ConfigurationProvider::class)->ingestion();
            $this->categorizeForeign ??= (bool) $ingestion->categorizeForeign;
            $this->catWebDL ??= (bool) $ingestion->categorizeWebDl;
        }

        $context = new ReleaseContext(
            releaseName: $releaseName,
            groupId: $groupId,
            groupName: $groupName,
            poster: $poster ?? '',
            categorizeForeign: $this->categorizeForeign,
            catWebDL: $this->catWebDL,
        );

        // Debug/logging need every intermediate result and Misc's full signal analysis.
        if ($this->defaultPipesUnmodified && ! $debug && ! config('nntmux.categorization.log', false)) {
            $category = $this->categorizeNative($context);
            if ($category !== null) {
                return ['categories_id' => $category];
            }
        }

        $passable = new CategorizationPassable($context, $debug);

        /** @var CategorizationPassable $result */
        $result = app(Pipeline::class)
            ->send($passable)
            ->through($this->pipes->values()->all())
            ->thenReturn();

        $this->logCategorization($result);

        return $result->toArray();
    }

    private function categorizeNative(ReleaseContext $context): ?int
    {
        $ffi = self::$nativeFfi ??= RustPipelineLibrary::resolveDomain('categorization-pipeline', self::NATIVE_CDEF) ?? false;
        if ($ffi === false) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $packed = $ffi->nntmux_categorize_pipeline(
                $context->releaseName, \strlen($context->releaseName),
                $context->groupName, \strlen($context->groupName),
                $context->poster, \strlen($context->poster),
                ($context->categorizeForeign ? 1 : 0) | ($context->catWebDL ? 2 : 0)
            );
        } catch (Throwable) {
            return null;
        }

        return \is_int($packed) && $packed >= 0 ? ($packed === 0 ? Category::OTHER_MISC : $packed & 0xFFFF) : null;
    }

    protected function logCategorization(CategorizationPassable $result): void
    {
        if (! config('nntmux.categorization.log', false)) {
            return;
        }

        $payload = [
            'release_name' => $result->context->releaseName,
            'group_name' => $result->context->groupName,
            'category_id' => $result->bestResult->categoryId,
            'matched_by' => $result->bestResult->matchedBy,
            'confidence' => $result->bestResult->confidence,
            'locked_to_misc' => $result->lockedToMisc,
            'misc_analysis' => $result->miscAnalysis,
        ];

        if ($result->lockedToMisc || $result->bestResult->matchedBy === 'group_only_low_signal') {
            Log::info('categorization.decision', $payload);
        }

        Log::debug('categorization.trace', $payload + ['all_results' => $result->allResults]);
    }

    /**
     * Get all registered categorizers (pipes).
     *
     * @return Collection<AbstractCategorizationPipe>
     */
    public function getCategorizers(): Collection // @phpstan-ignore missingType.generics
    {
        // Collection is mutable: after exposing it we cannot promise default pipe semantics.
        $this->defaultPipesUnmodified = false;

        return $this->pipes;
    }

    /**
     * Create a default pipeline with all standard categorizers.
     */
    public static function createDefault(): self
    {
        $pipeline = new self([
            new Pipes\MiscPipe,
            new Pipes\GroupNamePipe,
            new Pipes\XxxPipe,
            new Pipes\TvPipe,
            new Pipes\MoviePipe,
            new Pipes\BookPipe,
            new Pipes\MusicPipe,
            new Pipes\PcPipe,
            new Pipes\ConsolePipe,
            new Pipes\MiscSafetyNetPipe,
        ]);
        $pipeline->defaultPipesUnmodified = true;

        return $pipeline;
    }

    public static function useW413RustFfi(bool $enable = true): void
    {
        self::$w413RustFfiOverride = $enable;
    }

    public static function usesW413RustFfi(): bool
    {
        return self::$w413RustFfiOverride ?? self::W413_RUST_FFI_DEFAULT;
    }

    public static function resetW413RustFfi(): void
    {
        self::$w413RustFfiOverride = null;
        self::$w413RustFfiHandle = null;
    }

    /**
     * Shape summary for a batch of release names. Each name is trimmed (PHP's default set); empty
     * names count only as empty. Other names are dotted when they hold more dots than spaces, else
     * spaced when they hold a space, else compact; they carry a group suffix when the text after
     * their last "-" is 2-15 ASCII letters or digits, and are overlong beyond
     * W413_MAX_RELEASE_NAME_LENGTH bytes. Byte totals use trimmed lengths; non-strings count as empty.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, empty: int, dotted: int, spaced: int, compact: int, group_suffix: int, overlong: int, total_bytes: int, max_length: int}
     */
    public static function summarizeReleaseNameShapeBatch(array $releaseNames): array
    {
        return (self::usesW413RustFfi() ? self::summarizeReleaseNameShapeBatchNative($releaseNames) : null)
            ?? self::summarizeReleaseNameShapeBatchPhp($releaseNames);
    }

    /**
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, empty: int, dotted: int, spaced: int, compact: int, group_suffix: int, overlong: int, total_bytes: int, max_length: int}
     */
    public static function summarizeReleaseNameShapeBatchPhp(array $releaseNames): array
    {
        $empty = 0;
        $dotted = 0;
        $spaced = 0;
        $compact = 0;
        $groupSuffix = 0;
        $overlong = 0;
        $totalBytes = 0;
        $maxLength = 0;
        foreach ($releaseNames as $releaseName) {
            $name = trim(\is_string($releaseName) ? $releaseName : '');
            $length = \strlen($name);
            $totalBytes += $length;
            if ($length > $maxLength) {
                $maxLength = $length;
            }
            if ($length === 0) {
                $empty++;

                continue;
            }
            $spaces = substr_count($name, ' ');
            if (substr_count($name, '.') > $spaces) {
                $dotted++;
            } elseif ($spaces > 0) {
                $spaced++;
            } else {
                $compact++;
            }
            $dash = strrpos($name, '-');
            if ($dash !== false) {
                $suffixLength = $length - $dash - 1;
                if ($suffixLength >= 2 && $suffixLength <= 15 && strspn($name, self::W413_GROUP_SUFFIX_BYTES, $dash + 1) === $suffixLength) {
                    $groupSuffix++;
                }
            }
            if ($length > self::W413_MAX_RELEASE_NAME_LENGTH) {
                $overlong++;
            }
        }

        return [
            'total' => \count($releaseNames),
            'empty' => $empty,
            'dotted' => $dotted,
            'spaced' => $spaced,
            'compact' => $compact,
            'group_suffix' => $groupSuffix,
            'overlong' => $overlong,
            'total_bytes' => $totalBytes,
            'max_length' => $maxLength,
        ];
    }

    /**
     * Declines (null) for non-string items so PHP counts them.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, empty: int, dotted: int, spaced: int, compact: int, group_suffix: int, overlong: int, total_bytes: int, max_length: int}|null
     */
    public static function summarizeReleaseNameShapeBatchNative(array $releaseNames): ?array
    {
        $lengths = [];
        foreach ($releaseNames as $item) {
            if (! \is_string($item)) {
                return null;
            }
            $lengths[] = \strlen($item);
        }
        $blob = implode('', $releaseNames);
        $lens = pack('V*', ...$lengths);
        $fields = self::callW413Native($blob, $lens);
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'empty' => (int) $fields[1],
            'dotted' => (int) $fields[2],
            'spaced' => (int) $fields[3],
            'compact' => (int) $fields[4],
            'group_suffix' => (int) $fields[5],
            'overlong' => (int) $fields[6],
            'total_bytes' => (int) $fields[7],
            'max_length' => (int) $fields[8],
        ];
    }

    /**
     * Calls this class's Wave 41.3 export with the shared scratch buffer, retrying once at the size
     * it asks for. Returns its fields only when they match W413_FIELDS_PATTERN: exactly the expected
     * count of 1-19 digit decimals.
     *
     * @return list<string>|null
     */
    private static function callW413Native(string $blob, string $lens): ?array
    {
        $ffi = self::w413RustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 128, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w413_release_name_shape_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w413_release_name_shape_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $raw = FFI::string($buffer, $written);

            return preg_match(self::W413_FIELDS_PATTERN, $raw) === 1 ? explode("\x1F", $raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w413RustFfi(): ?FFI
    {
        if (self::$w413RustFfiHandle === null) {
            self::$w413RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w413', self::W413_FFI_CDEF) ?? false;
        }

        return self::$w413RustFfiHandle === false ? null : self::$w413RustFfiHandle;
    }
}
