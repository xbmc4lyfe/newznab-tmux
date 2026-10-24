<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Categorization\CategorizationService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class TestCategorization extends Command
{
    public const string FFI_CDEF = <<<'CDEF'
        ssize_t nntmux_cat_pipe_cmd_stats_row(const char *name_ptr, size_t name_len, const char *class_ptr, size_t class_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_cat_pipe_cmd_sample_row(const char *sample_ptr, size_t sample_len, uint8_t matched, char *out_buf, size_t out_cap);
        ssize_t nntmux_cat_pipe_cmd_comparison_status(uint8_t matched, char *out_buf, size_t out_cap);
        CDEF;

    /** Byte length of the UTF-8 `✓`/`✗` match marks. */
    private const int MARK_BYTES = 3;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected $signature = 'nntmux:test-categorization
                            {--release= : Release name to test}
                            {--poster= : Poster/From name to test}
                            {--compare : Compare pipeline vs legacy categorizer}
                            {--list-categorizers : List all registered categorizers}';

    protected $description = 'Test the new pipeline-based categorization service';

    public function handle(): int
    {
        $service = new CategorizationService;

        if ($this->option('list-categorizers')) {
            $this->listCategorizers($service);

            return 0;
        }

        $releaseName = $this->option('release');

        if (! $releaseName) {
            // Test with sample releases
            $this->testSampleReleases($service);

            return 0;
        }

        if ($this->option('compare')) {
            $this->compareResult($service, $releaseName);
        } else {
            $this->categorizeRelease($service, $releaseName);
        }

        return 0;
    }

    protected function listCategorizers(CategorizationService $service): void
    {
        $stats = $service->getCategorizerStats();

        $this->info('Registered Categorizers:');
        $this->table(
            ['Name', 'Priority', 'Class'],
            collect($stats)->map(fn ($s) => self::categorizerRow($s))->toArray()
        );
    }

    protected function categorizeRelease(CategorizationService $service, string $releaseName): void
    {
        $poster = $this->option('poster') ?? '';
        $result = $service->determineCategory(0, $releaseName, $poster, true);

        $this->info("Release: {$releaseName}");
        if ($poster) {
            $this->info("Poster: {$poster}");
        }
        $this->info("Category ID: {$result['categories_id']}");

        if (isset($result['debug'])) {
            $this->info("Matched By: {$result['debug']['matched_by']}");
            $this->info('Confidence: '.($result['debug']['final_confidence'] ?? 'N/A'));
        }
    }

    protected function compareResult(CategorizationService $service, string $releaseName): void
    {
        $comparison = $service->compare(0, $releaseName);

        $this->info("Release: {$releaseName}");
        $this->newLine();

        $matchStatus = self::comparisonStatus($comparison['match']);
        $this->line("Result: {$matchStatus}");
        $this->newLine();

        $this->table(
            ['', 'Pipeline', 'Legacy'],
            [
                ['Category ID', $comparison['pipeline']['category_id'], $comparison['legacy']['category_id']],
                ['Category Name', $comparison['pipeline']['category_name'], $comparison['legacy']['category_name']],
            ]
        );
    }

    protected function testSampleReleases(CategorizationService $service): void
    {
        $samples = [
            // TV
            'Game.of.Thrones.S08E06.720p.BluRay.x264-DEMAND',
            'The.Mandalorian.S03E08.2160p.WEB-DL.DDP5.1.Atmos.H.265-FLUX',
            '[SubsPlease] Jujutsu Kaisen - 47 (1080p) [ABC12345].mkv',

            // Movies
            'The.Matrix.Resurrections.2021.2160p.UHD.BluRay.x265-SURCODE',
            'Inception.2010.1080p.BluRay.x264-SPARKS',
            'Oppenheimer.2023.WEB-DL.1080p.H.264.AAC-LOL',

            // XXX
            'Brazzers.23.11.15.Model.Name.XXX.1080p.MP4-KTR',
            'SexBabesVR.23.10.20.Virtual.Reality.VR180.3D.SBS.2160p-VRSins',

            // Games
            'Cyberpunk.2077.Ultimate.Edition.v2.12.1-RUNE',
            'Elden.Ring.Shadow.of.the.Erdtree.PS5-DUPLEX',

            // Music
            'Taylor.Swift.Midnights.2022.FLAC-dL',
            'Various.Artists.Now.Thats.What.I.Call.Music.100.2018.MP3.320kbps',

            // Books
            'Brandon.Sanderson.Mistborn.Trilogy.EPUB',
            'OReilly.Learning.Python.6th.Edition.PDF',
        ];

        $this->info('Testing sample releases with both pipeline and legacy categorizers:');
        $this->newLine();

        $results = [];
        foreach ($samples as $sample) {
            $comparison = $service->compare(0, $sample);
            $results[] = self::sampleRow(
                $sample,
                $comparison['pipeline']['category_name'],
                $comparison['legacy']['category_name'],
                $comparison['match'],
            );
        }

        $this->table(
            ['Release', 'Pipeline', 'Legacy', 'Match'],
            $results
        );
    }

    /**
     * Overrides the PHP default for this command's helpers; `null` restores the PHP default.
     */
    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * `[name, priority, class]` table row for one categorizer stats entry.
     *
     * @param  array<array-key, mixed>  $stat
     * @return list<mixed>
     */
    public static function categorizerRow(array $stat): array
    {
        return self::$useRustFfi ? self::categorizerRowNative($stat) : self::categorizerRowPhp($stat);
    }

    /**
     * @param  array<array-key, mixed>  $stat
     * @return list<mixed>
     */
    public static function categorizerRowPhp(array $stat): array
    {
        return [$stat['name'], $stat['priority'], $stat['class']];
    }

    /**
     * @param  array<array-key, mixed>  $stat
     * @return list<mixed>
     */
    public static function categorizerRowNative(array $stat): array
    {
        $name = $stat['name'] ?? null;
        $class = $stat['class'] ?? null;
        $priority = $stat['priority'] ?? null;
        $ffi = self::ffi();
        if ($ffi === null || ! \is_string($name) || ! \is_string($class) || ! \is_int($priority)) {
            return self::categorizerRowPhp($stat);
        }

        $row = self::callForString($ffi, 'nntmux_cat_pipe_cmd_stats_row', \strlen($name) + \strlen($class), $name, \strlen($name), $class, \strlen($class));
        if ($row === null || \strlen($row) !== \strlen($name) + \strlen($class)) {
            return self::categorizerRowPhp($stat);
        }

        return [substr($row, 0, \strlen($name)), $priority, substr($row, \strlen($name))];
    }

    /**
     * Sample comparison row: the release truncated to 50 bytes (with `...`), both category
     * names and a `✓`/`✗` match mark.
     *
     * @return array{string, string, string, string}
     */
    public static function sampleRow(string $sample, string $pipelineName, string $legacyName, bool $match): array
    {
        return self::$useRustFfi
            ? self::sampleRowNative($sample, $pipelineName, $legacyName, $match)
            : self::sampleRowPhp($sample, $pipelineName, $legacyName, $match);
    }

    /**
     * @return array{string, string, string, string}
     */
    public static function sampleRowPhp(string $sample, string $pipelineName, string $legacyName, bool $match): array
    {
        return [
            substr($sample, 0, 50).(strlen($sample) > 50 ? '...' : ''),
            $pipelineName,
            $legacyName,
            $match ? '✓' : '✗',
        ];
    }

    /**
     * @return array{string, string, string, string}
     */
    public static function sampleRowNative(string $sample, string $pipelineName, string $legacyName, bool $match): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::sampleRowPhp($sample, $pipelineName, $legacyName, $match);
        }

        $row = self::callForString($ffi, 'nntmux_cat_pipe_cmd_sample_row', 64, $sample, \strlen($sample), $match ? 1 : 0);
        if ($row === null || \strlen($row) < self::MARK_BYTES) {
            return self::sampleRowPhp($sample, $pipelineName, $legacyName, $match);
        }

        return [substr($row, 0, -self::MARK_BYTES), $pipelineName, $legacyName, substr($row, -self::MARK_BYTES)];
    }

    /**
     * Console-styled `MATCH`/`MISMATCH` status for a single release comparison.
     */
    public static function comparisonStatus(bool $match): string
    {
        return self::$useRustFfi ? self::comparisonStatusNative($match) : self::comparisonStatusPhp($match);
    }

    public static function comparisonStatusPhp(bool $match): string
    {
        return $match ? '<fg=green>MATCH</>' : '<fg=red>MISMATCH</>';
    }

    public static function comparisonStatusNative(bool $match): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::comparisonStatusPhp($match);
        }

        return self::callForString($ffi, 'nntmux_cat_pipe_cmd_comparison_status', 32, $match ? 1 : 0) ?? self::comparisonStatusPhp($match);
    }

    /**
     * Calls a byte-output export with a scratch buffer, growing it once when Rust reports a larger requirement.
     */
    private static function callForString(FFI $ffi, string $symbol, int $estimate, mixed ...$args): ?string
    {
        try {
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(1, $estimate), $capacity);
            if ($outBuf === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $outBuf, $capacity]);
            if (\is_int($written) && $written < -1 && -$written > $capacity) {
                $outBuf = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($outBuf === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $outBuf, $capacity]);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($outBuf, $written);
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        try {
            $ffi = RustPipelineLibrary::resolveDomain('w29-categorization-pipes-commands', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        } catch (Throwable) {
            $ffi = null;
        }
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
