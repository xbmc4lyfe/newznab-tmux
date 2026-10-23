<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Service for prioritizing files during name matching.
 *
 * Handles file prioritization logic for PreDB matching and name extraction.
 */
class FilePrioritizer
{
    public const PREDB_IDENTITY_POLICY = 'b087d1d37dd4f6d4ef63bed1e5ef0193ed48d1344e1b37cb92c9180275ba5151';

    public const FFI_CDEF = <<<'CDEF'
        intptr_t nntmux_file_prioritizer_categorize_batch_nul(const char *input, size_t input_len, size_t count, uint32_t mode, char *out_buf, size_t out_cap);
        int32_t nntmux_file_prioritizer_crc_priority(const char *input, size_t input_len);
        intptr_t nntmux_file_prioritizer_rar_release_name_nul(const char *input, size_t input_len, size_t count, char *out_buf, size_t out_cap);
        CDEF;

    private const int MODE_MATCHING = 0;

    private const int MODE_PREDB = 1;

    private static bool $ffiResolved = false;

    private static ?FFI $defaultFfi = null;

    private static ?CData $defaultBuf = null;

    private static int $defaultCap = 0;

    private readonly bool $rustEligible;

    public function __construct(private readonly ?string $libraryPath = null)
    {
        $this->rustEligible = static::class === self::class;
    }

    /**
     * Video file extensions.
     */
    private const VIDEO_EXTENSIONS = ['mkv', 'avi', 'mp4', 'm4v', 'wmv', 'divx', 'ts', 'm2ts'];

    /**
     * Priority levels (lower = higher priority).
     */
    /** @phpstan-ignore classConstant.unused */
    private const PRIORITY_SRR = 1;

    private const PRIORITY_MAIN_RAR = 2;

    private const PRIORITY_FIRST_PART_RAR = 3;

    private const PRIORITY_VIDEO = 4;

    private const PRIORITY_NFO = 5;

    private const PRIORITY_OTHER_RAR = 6;

    private const PRIORITY_SAMPLE = 100;

    private const PRIORITY_OTHER = 50;

    /**
     * Generic DVD structure names and similar low-information files.
     *
     * @var list<string>
     */
    private const IGNORABLE_NAME_PATTERNS = [
        '/^(?:audio|video)[._-]?ts$/i',
        '/^vts[._-]?\d{1,2}[._-]?\d{1,2}$/i',
        '/^\d{1,3}$/',
    ];

    /**
     * Prioritize files for name matching.
     *
     * Returns files sorted by usefulness for name matching:
     * 1. SRR files (contain original release name)
     * 2. Main RAR files (not split parts)
     * 3. First split RAR parts
     * 4. Video files
     * 5. NFO files
     * 6. Other files
     *
     * @param  list<string>  $files  Array of filenames
     * @return list<string> Sorted array of filenames
     */
    public function prioritizeForMatching(array $files): array
    {
        if ($this->rustEligible) {
            $result = $this->categorizeRust($files, self::MODE_MATCHING);
            if ($result !== null) {
                return $result;
            }
        }

        $categorized = $this->categorizeFiles($files);

        // Merge in priority order
        return array_merge(
            $categorized['srr'],
            $categorized['mainRar'],
            $categorized['firstPart'],
            $categorized['video'],
            $categorized['nfo'],
            $categorized['other']
        );
    }

    /**
     * Prioritize files for PreDB matching.
     *
     * Similar to prioritizeForMatching but with slightly different priorities
     * optimized for PreDB filename lookups.
     *
     * @param  list<string>  $files  Array of filenames
     * @return list<string> Sorted array of filenames
     */
    public function prioritizeForPreDb(array $files): array
    {
        if ($this->rustEligible) {
            $result = $this->categorizeRust($files, self::MODE_PREDB);
            if ($result !== null) {
                return $result;
            }
        }

        $categorized = $this->categorizeFiles($files);

        // For PreDB, SRR files are most valuable, then main RARs
        return array_merge(
            $categorized['srr'],
            $categorized['mainRar'],
            $categorized['firstPart'],
            $categorized['video'],
            $categorized['other']
        );
    }

    /**
     * Categorize files into groups by type.
     *
     * @param  list<string>  $files  Array of filenames
     * @return array{srr: list<string>, mainRar: list<string>, firstPart: list<string>, video: list<string>, nfo: list<string>, other: list<string>} Categorized files
     */
    protected function categorizeFiles(array $files): array
    {
        $categories = [
            'srr' => [],
            'mainRar' => [],
            'firstPart' => [],
            'video' => [],
            'nfo' => [],
            'other' => [],
        ];

        foreach ($files as $file) {
            $lowerFile = strtolower($file);

            // Skip junk files that do not carry release identity.
            if ($this->shouldIgnoreForNameMatching($file) || $this->isSampleOrProof($file)) {
                continue;
            }

            // Categorize by type
            if ($this->isSrrFile($lowerFile)) {
                $categories['srr'][] = $file;
            } elseif ($this->isMainRar($lowerFile)) {
                $categories['mainRar'][] = $file;
            } elseif ($this->isFirstSplitRar($lowerFile)) {
                $categories['firstPart'][] = $file;
            } elseif ($this->isVideoFile($lowerFile)) {
                $categories['video'][] = $file;
            } elseif ($this->isNfoFile($lowerFile)) {
                $categories['nfo'][] = $file;
            } else {
                $categories['other'][] = $file;
            }
        }

        // Sort video files by length (longer names often more descriptive)
        usort($categories['video'], fn ($a, $b) => strlen($b) - strlen($a));
        usort($categories['mainRar'], fn ($a, $b) => strlen($b) - strlen($a));

        return $categories;
    }

    /**
     * Get priority value for a filename based on CRC matching.
     *
     * @param  string  $filename  The filename to check
     * @return int Priority value (lower = higher priority)
     */
    public function getCrcPriority(string $filename): int
    {
        if ($this->rustEligible && ($ffi = $this->resolveFfi()) !== null) {
            try {
                // @phpstan-ignore method.notFound
                $priority = $ffi->nntmux_file_prioritizer_crc_priority($filename, \strlen($filename));
                if (\is_int($priority) && $priority >= 0) {
                    return $priority;
                }
            } catch (Throwable) {
                // Fall through to the PHP implementation.
            }
        }

        $lower = strtolower($filename);

        if ($this->isSampleOrProof($filename)) {
            return self::PRIORITY_SAMPLE;
        }

        if ($this->isVideoFile($lower)) {
            return self::PRIORITY_VIDEO;
        }

        if ($this->isMainRar($lower)) {
            return self::PRIORITY_MAIN_RAR;
        }

        if ($this->isFirstSplitRar($lower)) {
            return self::PRIORITY_FIRST_PART_RAR;
        }

        if (preg_match('/\.(rar|r\d{2,3})$/i', $filename)) {
            return self::PRIORITY_OTHER_RAR;
        }

        if ($this->isNfoFile($lower)) {
            return self::PRIORITY_NFO;
        }

        return self::PRIORITY_OTHER;
    }

    /**
     * Check if a file is a sample or proof file.
     */
    protected function isSampleOrProof(string $filename): bool
    {
        return (bool) preg_match('/[\.\-_](sample|proof|subs?|thumbs?)[\.\-_]/i', $filename);
    }

    /**
     * Check if a file is an SRR file.
     */
    protected function isSrrFile(string $lowerFilename): bool
    {
        return str_ends_with($lowerFilename, '.srr') || str_ends_with($lowerFilename, '.srs');
    }

    /**
     * Check if a file is a main RAR (not split).
     */
    protected function isMainRar(string $lowerFilename): bool
    {
        return preg_match('/\.rar$/i', $lowerFilename) &&
               ! preg_match('/\.part\d+\.rar$/i', $lowerFilename);
    }

    /**
     * Check if a file is the first split RAR part.
     */
    protected function isFirstSplitRar(string $lowerFilename): bool
    {
        return (bool) preg_match('/\.part0*1\.rar$/i', $lowerFilename);
    }

    /**
     * Check if a file is a video file.
     */
    protected function isVideoFile(string $lowerFilename): bool
    {
        $extension = pathinfo($lowerFilename, PATHINFO_EXTENSION);

        return in_array($extension, self::VIDEO_EXTENSIONS, true);
    }

    /**
     * Check if a file is an NFO file.
     */
    protected function isNfoFile(string $lowerFilename): bool
    {
        return str_ends_with($lowerFilename, '.nfo');
    }

    /**
     * Skip files that are usually just DVD structure noise or URL spam.
     */
    protected function shouldIgnoreForNameMatching(string $filename): bool
    {
        $baseName = $this->extractFilenameFromPath($filename);

        if (preg_match('/\.url$/i', $baseName)) {
            return true;
        }

        $baseName = strtolower(trim((string) pathinfo($baseName, PATHINFO_FILENAME), " \t\n\r\0\x0B.-_"));

        foreach (self::IGNORABLE_NAME_PATTERNS as $pattern) {
            if (preg_match($pattern, $baseName)) {
                return true;
            }
        }

        return false;
    }

    protected function extractFilenameFromPath(string $filename): string
    {
        if (preg_match('/[\\\\\/]([^\\\\\/]+)$/', $filename, $match)) {
            return $match[1];
        }

        return $filename;
    }

    /**
     * Extract the most likely release name from multiple RAR files.
     *
     * Analyzes multiple RAR files from a release to determine the most likely
     * release name by finding common patterns.
     *
     * @param  array<array-key, mixed>  $rarFiles  Array of RAR filenames
     * @return string|null The most likely release name or null
     */
    public function findReleaseNameFromRarFiles(array $rarFiles): ?string
    {
        if ($this->rustEligible) {
            [$handled, $result] = $this->rarReleaseNameRust($rarFiles);
            if ($handled) {
                return $result;
            }
        }

        $candidates = [];

        foreach ($rarFiles as $file) {
            // Skip sample/proof files
            if ($this->isSampleOrProof($file)) {
                continue;
            }

            $extracted = $this->extractReleaseNameFromRar($file);
            if ($extracted !== null) {
                $candidates[$extracted] = ($candidates[$extracted] ?? 0) + 1;
            }
        }

        if (empty($candidates)) {
            return null;
        }

        // Return the most common candidate
        arsort($candidates);
        $topCount = reset($candidates);
        $topCandidates = array_filter($candidates, fn ($count) => $count === $topCount);

        // If multiple candidates have the same count, prefer the longest one
        $best = null;
        foreach (array_keys($topCandidates) as $candidate) {
            if ($best === null || strlen($candidate) > strlen($best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * Extract release name from a RAR filename.
     */
    protected function extractReleaseNameFromRar(string $filename): ?string
    {
        // Extract filename from path
        if (preg_match('/[\\\\\/]([^\\\\\/]+)$/', $filename, $match)) {
            $filename = $match[1];
        }

        // Remove RAR extensions
        $baseName = preg_replace('/\.(rar|r\d{2,4}|part\d+\.rar|\d{3})$/i', '', $filename);

        // Check if it looks like a scene release name
        if (preg_match('/^([a-z0-9][a-z0-9._-]+[a-z0-9])\-([a-z0-9]{2,15})$/i', $baseName, $match)) {
            // Clean up any remaining artifacts
            $baseName = preg_replace('/[._-]?(sample|proof|subs?)$/i', '', $baseName);
            $baseName = trim($baseName, '.-_');

            if (strlen($baseName) >= 10) {
                return $baseName;
            }
        }

        return null;
    }

    private function resolveFfi(): ?FFI
    {
        if ($this->libraryPath !== null) {
            return RustPipelineLibrary::resolveDomain('file_prioritizer', self::FFI_CDEF, $this->libraryPath);
        }
        if (! self::$ffiResolved) {
            self::$defaultFfi = RustPipelineLibrary::resolveDomain('file_prioritizer', self::FFI_CDEF);
            self::$ffiResolved = true;
        }

        return self::$defaultFfi;
    }

    private function scratch(FFI $ffi, int $minBytes, int &$cap): ?CData
    {
        if ($this->libraryPath === null && self::$defaultBuf !== null && $minBytes <= self::$defaultCap) {
            $cap = self::$defaultCap;

            return self::$defaultBuf;
        }
        $buf = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $cap);
        if ($this->libraryPath === null && $buf !== null) {
            self::$defaultBuf = $buf;
            self::$defaultCap = $cap;
        }

        return $buf;
    }

    /**
     * Joins string values with NUL; returns null when a value is not a NUL-free string
     * so the PHP path keeps its exact (including TypeError) behaviour.
     *
     * @param  array<array-key, mixed>  $files
     */
    private static function joinNul(array $files): ?string
    {
        foreach ($files as $file) {
            if (! \is_string($file)) {
                return null;
            }
        }
        $joined = implode("\0", $files);
        if (substr_count($joined, "\0") !== \count($files) - 1) {
            return null;
        }

        return $joined;
    }

    /**
     * @param  list<string>  $files
     * @return list<string>|null
     */
    private function categorizeRust(array $files, int $mode): ?array
    {
        $count = \count($files);
        if ($count === 0) {
            return [];
        }
        $ffi = $this->resolveFfi();
        if ($ffi === null) {
            return null;
        }
        $joined = self::joinNul($files);
        if ($joined === null) {
            return null;
        }

        try {
            $len = \strlen($joined);
            $cap = 0;
            $buf = $this->scratch($ffi, $len + 64, $cap);
            if ($buf === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_file_prioritizer_categorize_batch_nul($joined, $len, $count, $mode, $buf, $cap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
            if ($written === 0) {
                return [];
            }

            return explode("\0", FFI::string($buf, $written - 1));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<array-key, mixed>  $rarFiles
     * @return array{0: bool, 1: ?string}
     */
    private function rarReleaseNameRust(array $rarFiles): array
    {
        $count = \count($rarFiles);
        if ($count === 0) {
            return [true, null];
        }
        $ffi = $this->resolveFfi();
        if ($ffi === null) {
            return [false, null];
        }
        $joined = self::joinNul($rarFiles);
        if ($joined === null) {
            return [false, null];
        }

        try {
            $len = \strlen($joined);
            $cap = 0;
            $buf = $this->scratch($ffi, $len + 64, $cap);
            if ($buf === null) {
                return [false, null];
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_file_prioritizer_rar_release_name_nul($joined, $len, $count, $buf, $cap);
            if (! \is_int($written) || $written < 0) {
                return [false, null];
            }

            return [true, $written === 0 ? null : FFI::string($buf, $written)];
        } catch (Throwable) {
            return [false, null];
        }
    }
}
