<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;

/**
 * Service for parsing and extracting information from NZB file contents.
 * Handles parsing NZB XML structure and extracting file lists, extensions, sizes, etc.
 */
class NzbParserService
{
    /**
     * Rust NFO/PAR2 candidate scanner (`rust/pipeline-ffi/src/nfo.rs`), shared with
     * {@see NzbContentsService::parseNzb()}.
     */
    public const string NFO_FFI_CDEF = <<<'C'
        ssize_t nntmux_nzb_detect_nfo_file(const char *title, size_t len, char *out_buf, size_t out_cap);
        int32_t nntmux_nzb_detect_hidden_nfo_file(const char *title, size_t len);
        int32_t nntmux_nzb_detect_par2_index_file(const char *title, size_t len);
        int32_t nntmux_nzb_extract_parts_total(const char *title, size_t len);
        ssize_t nntmux_nzb_classify_nfo_par2_batch_nul(const char *records, size_t records_len, uint32_t flags, char *out_buf, size_t out_cap);
        ssize_t nntmux_nzb_classify_nfo_par2_packed(const char *xml, size_t xml_len, uint32_t flags, char *out_buf, size_t out_cap);
        C;

    private static FFI|false|null $nfoFfi = null;

    /**
     * Default for the Wave 40.2 toggle only; it stays false because the paired benchmark in
     * tests/Unit/RustNzbServiceParserManifestWriteContentsW40ParityTest.php is below 1.5x.
     */
    public const bool W402_RUST_FFI_DEFAULT = false;

    private static ?bool $useW402RustFfi = null;

    public static function useW402RustFfi(?bool $use): void
    {
        self::$useW402RustFfi = $use;
    }

    public static function usesW402RustFfi(): bool
    {
        return self::$useW402RustFfi ?? self::W402_RUST_FFI_DEFAULT;
    }

    public static function resetW402RustFfi(): void
    {
        self::$useW402RustFfi = null;
    }

    /**
     * Summarize parsed NZB files whose `segments` hold `number`/`bytes` entries; a non-array file or segment list counts as
     * no segments. A file with more than max(0, $maxSegmentsPerFile) segments is oversized; otherwise it is complete when its
     * integer numbers are exactly 1..n in any order (n >= 1) and gapped when not. Integer segment bytes from every file sum,
     * negatives as zero, saturating at PHP_INT_MAX.
     *
     * @param  array<array-key, mixed>  $files
     * @return array{files: int, complete: int, gapped: int, oversized: int, total_segment_bytes: int}
     */
    public static function summarizeParsedFileSegments(array $files, int $maxSegmentsPerFile = 10000): array
    {
        return (self::usesW402RustFfi() ? self::summarizeParsedFileSegmentsNative($files, $maxSegmentsPerFile) : null)
            ?? self::summarizeParsedFileSegmentsPhp($files, $maxSegmentsPerFile);
    }

    /**
     * @param  array<array-key, mixed>  $files
     * @return array{files: int, complete: int, gapped: int, oversized: int, total_segment_bytes: int}
     */
    private static function summarizeParsedFileSegmentsPhp(array $files, int $maxSegmentsPerFile): array
    {
        $limit = max(0, $maxSegmentsPerFile);
        $counts = [0, 0, 0];
        $total = 0;
        foreach ($files as $file) {
            $segments = self::parsedFileSegments($file);
            $count = \count($segments);
            $seen = [];
            $ordered = $count > 0;
            foreach ($segments as $segment) {
                [$number, $bytes] = self::parsedSegmentFields($segment);
                $bytes = max(0, $bytes);
                $total = $total > PHP_INT_MAX - $bytes ? PHP_INT_MAX : $total + $bytes;
                if ($ordered && ($number < 1 || $number > $count || isset($seen[$number]))) {
                    $ordered = false;
                }
                $seen[$number] = true;
            }
            $counts[$count > $limit ? 2 : ($ordered ? 0 : 1)]++;
        }

        return self::parsedFileSegmentsSummary(\count($files), $counts, $total);
    }

    /**
     * @param  array<array-key, mixed>  $files
     * @return array{files: int, complete: int, gapped: int, oversized: int, total_segment_bytes: int}|null
     */
    private static function summarizeParsedFileSegmentsNative(array $files, int $maxSegmentsPerFile): ?array
    {
        $size = 2 + \count($files);
        foreach ($files as $file) {
            $size += 2 * \count(self::parsedFileSegments($file));
        }
        if ($size > NzbService::W402_MAX_INPUT / 8) {
            return null;
        }
        $words = [$maxSegmentsPerFile, \count($files)];
        foreach ($files as $file) {
            $segments = self::parsedFileSegments($file);
            $words[] = \count($segments);
            foreach ($segments as $segment) {
                [$words[], $words[]] = self::parsedSegmentFields($segment);
            }
        }
        $slots = NzbService::evaluateW402Native(1, pack('q*', ...$words), 5);
        if ($slots === null) {
            return null;
        }
        [$count, $complete, $gapped, $oversized, $total] = $slots;
        if ($count !== \count($files) || min($complete, $gapped, $oversized, $total) < 0 || $complete + $gapped + $oversized !== $count) {
            return null;
        }

        return self::parsedFileSegmentsSummary($count, [$complete, $gapped, $oversized], $total);
    }

    /** @return array<array-key, mixed> */
    private static function parsedFileSegments(mixed $file): array
    {
        $segments = \is_array($file) ? ($file['segments'] ?? null) : null;

        return \is_array($segments) ? $segments : [];
    }

    /** @return array{int, int} the segment number and bytes, each 0 unless an integer */
    private static function parsedSegmentFields(mixed $segment): array
    {
        if (! \is_array($segment)) {
            return [0, 0];
        }
        $number = $segment['number'] ?? 0;
        $bytes = $segment['bytes'] ?? 0;

        return [\is_int($number) ? $number : 0, \is_int($bytes) ? $bytes : 0];
    }

    /**
     * @param  array{int, int, int}  $counts  complete, gapped, oversized
     * @return array{files: int, complete: int, gapped: int, oversized: int, total_segment_bytes: int}
     */
    private static function parsedFileSegmentsSummary(int $files, array $counts, int $total): array
    {
        return ['files' => $files, 'complete' => $counts[0], 'gapped' => $counts[1], 'oversized' => $counts[2], 'total_segment_bytes' => $total];
    }

    /**
     * Retrieve various information on an NZB file (the subject, # of pars,
     * file extensions, file sizes, file completion, group names, # of parts).
     *
     * @param  string  $nzb  The NZB contents in a string.
     * @param  array<string, mixed>  $options  Optional settings:
     *                                         - 'no-file-key': Use numeric keys instead of subject (default: true)
     *                                         - 'strip-count': Strip file/part count from subject for sorting (default: false)
     * @return array<string, mixed> $result Empty if not an NZB or the contents of the NZB.
     */
    public function parseNzbFileList(string $nzb, array $options = [], bool $useNative = true): array
    {
        if (! $nzb) {
            return [];
        }

        $defaults = [
            'no-file-key' => true,
            'strip-count' => false,
        ];
        $options += $defaults;

        if ($useNative) {
            $lines = RustPipelineLibrary::nzbParseFileLines($nzb);
            if ($lines !== null) {
                $result = [];
                $noFileKey = $options['no-file-key'] !== false;
                $stripCount = (bool) $options['strip-count'];
                $numIdx = 0;

                foreach ($lines as $line) {
                    $fields = explode("\0", $line, 8);
                    if (\count($fields) !== 8) {
                        return $this->parseNzbFileListPhp($nzb, $options);
                    }
                    [$title, $ext, $firstSeg, $size, $partsActual, $partsTotal, $groupsStr, $segmentsStr] = $fields;

                    if ($noFileKey) {
                        $key = $numIdx++;
                    } else {
                        $key = $title;
                        if ($stripCount) {
                            $key = preg_replace('#\d+[- ._]?(/|\||[o0]f)[- ._]?\d+?(?![- ._]\d)#i', '', $key);
                            if (str_contains($key, '.par2') && ! preg_match('#\.vol\d+\+\d+\.par2#i', $key)) {
                                $key = str_replace('.par2', '.vol0.par2', $key);
                            } elseif (preg_match('#\.rar[^a-z0-9]#i', $key) && ! preg_match('#\.part\d+\.rar$#i', $key)) {
                                $key = preg_replace('#\.rar(?:[^a-z0-9])#i', '.part0.rar', $key);
                            }
                        }
                    }

                    $segs = $segmentsStr === '' ? [] : explode("\t", $segmentsStr);
                    $grps = $groupsStr === '' ? [] : explode("\t", $groupsStr);

                    if (! isset($result[$key])) {
                        $entry = [
                            'title' => $title,
                            'filecount' => 1,
                            'ext' => $ext,
                            'segments' => $segs,
                            'firstsegment' => $firstSeg === '1',
                            'size' => (int) $size,
                        ];
                        if ($partsTotal !== '') {
                            $entry['partstotal'] = $partsTotal;
                        }
                        $entry['partsactual'] = (int) $partsActual;
                        $entry['groups'] = $grps;
                        $result[$key] = $entry;
                    } else {
                        $result[$key]['title'] = $title;
                        $result[$key]['filecount']++;
                        $result[$key]['ext'] = $ext;
                        if ($segs !== []) {
                            array_push($result[$key]['segments'], ...$segs);
                        }
                        $result[$key]['size'] = (int) $size;
                        if ($partsTotal !== '') {
                            $result[$key]['partstotal'] = $partsTotal;
                        }
                        $result[$key]['partsactual'] = (int) $partsActual;
                        if ($grps !== []) {
                            array_push($result[$key]['groups'], ...$grps);
                        }
                    }
                }

                return $result;
            }
        }

        return $this->parseNzbFileListPhp($nzb, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<int|string, mixed>
     */
    public function parseNzbFileListPhp(string $nzb, array $options = []): array
    {
        $defaults = [
            'no-file-key' => true,
            'strip-count' => false,
        ];
        $options += $defaults;

        $i = 0;
        $result = [];

        if (! $nzb) {
            return $result;
        }

        $xml = @simplexml_load_string(str_replace("\x0F", '', $nzb));
        if (! $xml || strtolower($xml->getName()) !== 'nzb') {
            return $result;
        }

        foreach ($xml->file as $file) {
            // Subject.
            $title = (string) $file->attributes()->subject;

            if ($options['no-file-key'] === false) {
                $i = $title;
                if ($options['strip-count']) {
                    // Strip file / part count to get proper sorting.
                    $i = preg_replace('#\d+[- ._]?(/|\||[o0]f)[- ._]?\d+?(?![- ._]\d)#i', '', $i);
                    // Change .rar and .par2 to be sorted before .part0x.rar and .volxxx+xxx.par2
                    if (str_contains($i, '.par2') && ! preg_match('#\.vol\d+\+\d+\.par2#i', $i)) {
                        $i = str_replace('.par2', '.vol0.par2', $i);
                    } elseif (preg_match('#\.rar[^a-z0-9]#i', $i) && ! preg_match('#\.part\d+\.rar$#i', $i)) {
                        $i = preg_replace('#\.rar(?:[^a-z0-9])#i', '.part0.rar', $i);
                    }
                }
            }

            $result[$i]['title'] = $title;
            // Stripped subjects can merge several NZB files into one entry.
            $result[$i]['filecount'] = ($result[$i]['filecount'] ?? 0) + 1;

            // Extensions.
            if (preg_match(
                '/\.(\d{2,3}|7z|ace|ai7|srr|srt|sub|aiff|asc|avi|audio|bin|bz2|'
                .'c|cfc|cfm|chm|class|conf|cpp|cs|css|csv|cue|deb|divx|doc|dot|'
                .'eml|enc|exe|file|gif|gz|hlp|htm|html|image|iso|jar|java|jpeg|'
                .'jpg|js|lua|m|m3u|mkv|mm|mov|mp3|mp4|mpg|nfo|nzb|odc|odf|odg|odi|odp|'
                .'ods|odt|ogg|par2|parity|pdf|pgp|php|pl|png|ppt|ps|py|r\d{2,3}|'
                .'ram|rar|rb|rm|rpm|rtf|sfv|sig|sql|srs|swf|sxc|sxd|sxi|sxw|tar|'
                .'tex|tgz|txt|vcf|video|vsd|wav|wma|wmv|xls|xml|xpi|xvid|zip7|zip)'
                .'[" ](?!([\)|\-]))/i',
                $title,
                $ext
            )
            ) {
                if (preg_match('/\.r\d{2,3}/i', $ext[0])) {
                    $ext[1] = 'rar';
                }
                $result[$i]['ext'] = strtolower($ext[1]);
            } else {
                $result[$i]['ext'] = '';
            }

            $fileSize = $numSegments = 0;

            // Parts.
            if (! isset($result[$i]['segments'])) {
                $result[$i]['segments'] = [];
            }

            // File size.
            $segments = [];
            foreach ($file->segments->segment as $segment) {
                $number = $segment->attributes()->number;
                $segments[] = [$number === null ? null : (int) $number, (string) $segment];
                $fileSize += $segment->attributes()->bytes;
                $numSegments++;
            }
            // Segments may be listed out of order; readers expect them in file order.
            if (! in_array(null, array_column($segments, 0), true)) {
                usort($segments, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            }
            if (! isset($result[$i]['firstsegment'])) {
                // Segment 1 holds the file header; without it the file can't be inspected.
                $result[$i]['firstsegment'] = $segments === [] || ($segments[0][0] ?? 1) === 1;
            }
            array_push($result[$i]['segments'], ...array_column($segments, 1));
            $result[$i]['size'] = $fileSize;

            // File completion.
            if (preg_match('/(\d+)\)$/', $title, $parts)) {
                $result[$i]['partstotal'] = $parts[1];
            }
            $result[$i]['partsactual'] = $numSegments;

            // Groups.
            if (! isset($result[$i]['groups'])) {
                $result[$i]['groups'] = [];
            }
            foreach ($file->groups->group as $g) {
                $result[$i]['groups'][] = (string) $g;
            }

            if ($options['no-file-key']) {
                $i++;
            }
        }

        return $result;
    }

    /**
     * Load and parse an NZB file from its contents string.
     *
     * @param  string  $nzbContents  The decompressed NZB file contents
     * @param  bool  $echoErrors  Whether to echo errors on parse failure
     * @param  string  $guid  Optional GUID for error messages
     * @return \SimpleXMLElement|false The parsed NZB file as SimpleXMLElement or false on failure
     */
    public function parseNzbXml(string $nzbContents, bool $echoErrors = false, string $guid = ''): \SimpleXMLElement|false
    {
        if (empty($nzbContents)) {
            return false;
        }

        // Safely parse the XML content
        libxml_use_internal_errors(true);
        $nzbFile = simplexml_load_string($nzbContents);

        if ($nzbFile === false) {
            if ($echoErrors) {
                $errors = libxml_get_errors();
                $errorMsg = ! empty($errors) ? ' - XML error: '.$errors[0]->message : '';
                echo PHP_EOL."Unable to load NZB: {$guid} appears to be an invalid NZB{$errorMsg}, skipping.".PHP_EOL;
                libxml_clear_errors();
            }

            return false;
        }

        return $nzbFile;
    }

    /**
     * Get the file extension from a subject line.
     *
     * @param  string  $subject  The file subject/name
     * @return string The detected extension, or empty string if none found
     */
    public function detectFileExtension(string $subject): string
    {
        if (preg_match(
            '/\.(\d{2,3}|7z|ace|ai7|srr|srt|sub|aiff|asc|avi|audio|bin|bz2|'
            .'c|cfc|cfm|chm|class|conf|cpp|cs|css|csv|cue|deb|divx|doc|dot|'
            .'eml|enc|exe|file|gif|gz|hlp|htm|html|image|iso|jar|java|jpeg|'
            .'jpg|js|lua|m|m3u|mkv|mm|mov|mp3|mp4|mpg|nfo|nzb|odc|odf|odg|odi|odp|'
            .'ods|odt|ogg|par2|parity|pdf|pgp|php|pl|png|ppt|ps|py|r\d{2,3}|'
            .'ram|rar|rb|rm|rpm|rtf|sfv|sig|sql|srs|swf|sxc|sxd|sxi|sxw|tar|'
            .'tex|tgz|txt|vcf|video|vsd|wav|wma|wmv|xls|xml|xpi|xvid|zip7|zip)'
            .'[" ](?!([\)|\-]))/i',
            $subject,
            $ext
        )
        ) {
            if (preg_match('/\.r\d{2,3}/i', $ext[0])) {
                return 'rar';
            }

            return strtolower($ext[1]);
        }

        return '';
    }

    /**
     * Check if a subject indicates an NFO file.
     *
     * @param  string  $subject  The file subject
     * @return array<string, mixed>|false Returns array with detection info or false if not an NFO
     */
    public function detectNfoFile(string $subject): array|false
    {
        // Standard NFO extensions
        if (preg_match('/\.\b(nfo|diz|info?)\b(?![.-])/i', $subject)) {
            return ['hidden' => false, 'priority' => 1];
        }

        // Alternative NFO naming patterns (group-specific or obfuscated)
        if (preg_match('/(?:^|["\s])(?:file(?:_?id)?|readme|release|info(?:rmation)?|about|desc(?:ription)?|notes?|read\.?me|00-|000-|0-|_-_).*?\.(?:txt|nfo|diz)(?:["\s]|$)/i', $subject)) {
            return ['hidden' => false, 'priority' => 2];
        }

        return false;
    }

    /**
     * Check if a subject might indicate a hidden NFO file.
     *
     * @param  string  $subject  The file subject
     * @param  int  $segmentCount  The number of segments in the file
     * @return array<string, mixed>|false Returns array with detection info or false if not a hidden NFO
     */
    public function detectHiddenNfoFile(string $subject, int $segmentCount): array|false
    {
        $isHiddenNfoCandidate = false;

        // Pattern 1: Single segment files with (1/1)
        if ($segmentCount === 1 && preg_match('/\(1\/1\)$/i', $subject)) {
            $isHiddenNfoCandidate = true;
        }

        // Pattern 2: Small segment count (1-2) with NFO-like names but no extension
        if (! $isHiddenNfoCandidate && $segmentCount <= 2 && preg_match('/(?:^|["\s])(?:nfo|info|readme|release|file_?id|about)(?:["\s]|$)/i', $subject)) {
            $isHiddenNfoCandidate = true;
        }

        // Pattern 3: Scene-style NFO naming (group-release.nfo without extension visible)
        if (! $isHiddenNfoCandidate && $segmentCount === 1 && preg_match('/^[a-z0-9._-]+["\s]*\(1\/1\)/i', $subject)) {
            // Check for scene-like naming pattern
            if (preg_match('/^[a-z0-9]+[._-][a-z0-9._-]+["\s]*\(1\/1\)/i', $subject)) {
                $isHiddenNfoCandidate = true;
            }
        }

        // Pattern 4: Very small files (NFOs are typically small)
        // Files described as very small in bytes could be NFOs
        if (! $isHiddenNfoCandidate && $segmentCount === 1 && preg_match('/yEnc\s*\(\d+\)\s*\[1\/1\]/i', $subject)) {
            $isHiddenNfoCandidate = true;
        }

        if (! $isHiddenNfoCandidate) {
            return false;
        }

        // Enhanced exclusion: check if it's NOT likely another common file type
        $excludedExtensions = '/\.(?:'.
            // Executables
            'exe|com|bat|cmd|scr|dll|msi|pkg|deb|rpm|apk|ipa|app|'.
            // Archives
            'zip|rar|[rst]\d{2}|7z|ace|tar|gz|bz2|xz|lzma|cab|iso|bin|cue|img|mdf|nrg|dmg|vhd|'.
            // Audio
            'mp3|flac|ogg|aac|wav|wma|m4a|opus|ape|wv|mpc|'.
            // Video
            'avi|mkv|mp4|mov|wmv|mpg|mpeg|ts|vob|m2ts|webm|flv|ogv|divx|xvid|'.
            // Images
            'jpg|jpeg|png|gif|bmp|tif|tiff|psd|webp|svg|ico|raw|cr2|nef|'.
            // Documents
            'pdf|doc|docx|xls|xlsx|ppt|pptx|odt|ods|odp|rtf|epub|mobi|azw|'.
            // Code
            'html|htm|css|js|php|py|java|c|cpp|h|cs|sql|json|xml|yml|yaml|'.
            // Data
            'db|dbf|mdb|accdb|sqlite|csv|'.
            // Verification
            'par2?|sfv|md5|sha1|sha256|sha512|crc|'.
            // Misc
            'url|lnk|cfg|ini|inf|sys|tmp|bak|log|srt|sub|idx|ass|ssa|vtt'.
            ')\b/i';

        if (preg_match($excludedExtensions, $subject)) {
            return false;
        }

        return ['hidden' => true, 'priority' => 10];
    }

    /**
     * Check if a subject indicates a PAR2 index file.
     *
     * @param  string  $subject  The file subject
     * @return bool True if it's a PAR2 index file
     */
    public function detectPar2IndexFile(string $subject): bool
    {
        return (bool) preg_match('/\.par2$/i', $subject);
    }

    /**
     * Calculate artificial parts from a subject line.
     *
     * @param  string  $subject  The file subject
     * @return int The estimated total parts, or 0 if not determinable
     */
    public function extractPartsTotal(string $subject): int
    {
        // Improve artificial parts calculation robustness (e.g., "[15/20]", "(15/20)")
        if (preg_match('/(?:[(\[])?(\d+)[\/)\\]](\d+)[)\]]?$/', $subject, $parts)) {
            if ((int) $parts[2] > 0) {
                return (int) $parts[2];
            }
        }

        // Fallback to original simple check
        if (preg_match('/(\d+)\)$/', $subject, $parts)) {
            return (int) $parts[1];
        }

        return 0;
    }

    /**
     * The candidate scanner library, or null when it is unavailable or `LC_CTYPE` makes PCRE's
     * character classes differ from the C locale the Rust matchers reproduce.
     */
    public static function nfoCandidateFfi(): ?FFI
    {
        if (! \in_array(setlocale(LC_CTYPE, '0'), ['C', 'POSIX', 'C.UTF-8', 'C.utf8'], true)) {
            return null;
        }
        $ffi = self::$nfoFfi ??= RustPipelineLibrary::resolveDomain('nzb_nfo_candidates', self::NFO_FFI_CDEF) ?? false;

        return $ffi === false ? null : $ffi;
    }

    /*
     * Rust equivalents of the subject detectors, kept for parity tests: one FFI call per subject
     * does not reach the 1.5x gate (see tests/Unit/RustNzbNfoCandidateScannerParityTest.php), so
     * production keeps the PCRE implementations above. $supported is false when Rust did not run.
     */

    /** @return array<string, mixed>|false */
    public function detectNfoFileNative(string $subject, bool &$supported = false): array|false
    {
        $supported = false;
        $ffi = self::nfoCandidateFfi();
        if ($ffi === null) {
            return false;
        }
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 16, $capacity);
        if ($scratch === null) {
            return false;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_nzb_detect_nfo_file($subject, \strlen($subject), $scratch, $capacity);
        if ($written !== 0 && $written !== 1) {
            return false;
        }
        $supported = true;

        return $written === 0 ? false : ['hidden' => false, 'priority' => \ord($scratch[0])];
    }

    /** @return array<string, mixed>|false */
    public function detectHiddenNfoFileNative(string $subject, int $segmentCount, bool &$supported = false): array|false
    {
        $supported = false;
        $ffi = self::nfoCandidateFfi();
        if ($ffi === null) {
            return false;
        }
        // @phpstan-ignore method.notFound
        $mask = $ffi->nntmux_nzb_detect_hidden_nfo_file($subject, \strlen($subject));
        if (! \is_int($mask) || $mask < 0) {
            return false;
        }
        $supported = true;
        $candidate = ($segmentCount === 1 && ($mask & 0b1101) !== 0) || ($segmentCount <= 2 && ($mask & 0b10) !== 0);

        return $candidate && ($mask & 0b10000) === 0 ? ['hidden' => true, 'priority' => 10] : false;
    }

    public function detectPar2IndexFileNative(string $subject, bool &$supported = false): bool
    {
        $supported = false;
        $ffi = self::nfoCandidateFfi();
        if ($ffi === null) {
            return false;
        }
        // @phpstan-ignore method.notFound
        $result = $ffi->nntmux_nzb_detect_par2_index_file($subject, \strlen($subject));
        $supported = $result === 0 || $result === 1;

        return $result === 1;
    }

    public function extractPartsTotalNative(string $subject, bool &$supported = false): int
    {
        $supported = false;
        $ffi = self::nfoCandidateFfi();
        if ($ffi === null) {
            return 0;
        }
        // @phpstan-ignore method.notFound
        $total = $ffi->nntmux_nzb_extract_parts_total($subject, \strlen($subject));
        if (! \is_int($total) || $total < 0) {
            return 0;
        }
        $supported = true;

        return $total;
    }

    /** Wave 55.2 paired benchmarks decide this default; see RustNfoServiceDbNzbStoreNfoImportNzbContentsParserW55ParityTest. */
    private const bool W55_RUST_FFI_DEFAULT = true;

    private const string W55_FFI_CDEF = <<<'C'
        intptr_t nntmux_w552_nzb_parser_subject_plan_batch(const char *subjects, size_t subjects_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 55 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns `[binding, buffer, capacity]` with room for at
     * least $val words (null without the library).
     */
    private static function w55RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w55', self::W55_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w55RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW55RustFfi(): bool
    {
        $enabled = self::w55RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W55_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planSubjectBatch() routed to Rust ($enabled) or PHP, then restores the previous
     * routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW55RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w55RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w55RustFfiState('set', $previous);
        }
    }

    /**
     * Batch subject plan, as parseNzbFileList()/parseNzb() run the detectors on each file subject.
     * Two words per subject: `flags | crc32(subject) << 32` and extractPartsTotal(). Flags:
     * detectNfoFile()'s priority (0-2) in bits 0-1, 4 detectHiddenNfoFile() with the subject's
     * segment count, 8 detectPar2IndexFile(), 16 a positive parts total. NFO, hidden NFO and PAR2
     * index subjects, the parts sum (saturating at PHP_INT_MAX), the largest parts total and the
     * XXH64 digest of all earlier words follow.
     *
     * @param  list<string>  $subjects
     * @param  list<int>  $segmentCounts
     * @return list<int>
     */
    public static function planSubjectBatch(array $subjects, array $segmentCounts): array
    {
        return self::usesW55RustFfi()
            ? self::planSubjectBatchNative($subjects, $segmentCounts)
            : self::planSubjectBatchPhp($subjects, $segmentCounts);
    }

    /**
     * @param  list<string>  $subjects
     * @param  list<int>  $segmentCounts
     * @return list<int>
     */
    public static function planSubjectBatchPhp(array $subjects, array $segmentCounts): array
    {
        $parser = new self;
        $segmentCounts = array_values($segmentCounts);
        $out = [];
        $nfo = $hidden = $par2 = $sum = $largest = 0;
        foreach (array_values($subjects) as $i => $subject) {
            $detected = $parser->detectNfoFile($subject);
            $priority = $detected === false ? 0 : ($detected['priority'] === 2 ? 2 : 1);
            $isHidden = $parser->detectHiddenNfoFile($subject, $segmentCounts[$i] ?? 0) !== false;
            $isPar2 = $parser->detectPar2IndexFile($subject);
            $parts = $parser->extractPartsTotal($subject);
            $nfo += $priority > 0 ? 1 : 0;
            $hidden += (int) $isHidden;
            $par2 += (int) $isPar2;
            $sum = $sum > PHP_INT_MAX - $parts ? PHP_INT_MAX : $sum + $parts;
            $largest = max($largest, $parts);
            array_push($out, $priority | ($isHidden ? 4 : 0) | ($isPar2 ? 8 : 0) | ($parts > 0 ? 16 : 0) | crc32($subject) << 32, $parts);
        }
        array_push($out, $nfo, $hidden, $par2, $sum, $largest);
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, PCRE's character tables are not
     * the default (C) ones, a subject holds a NUL byte or a parts total saturates PHP's integer cast,
     * or the lists differ in length or are not lists.
     *
     * @param  list<string>  $subjects
     * @param  list<int>  $segmentCounts
     * @return list<int>
     */
    public static function planSubjectBatchNative(array $subjects, array $segmentCounts): array
    {
        $count = \count($subjects);
        $words = 2 * $count + 6;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w55RustFfiState('out', $words);
        if ($state === null || \count($segmentCounts) !== $count || ! array_is_list($subjects) || ! array_is_list($segmentCounts)
            || ! self::pcreUsesDefaultTables()) {
            return self::planSubjectBatchPhp($subjects, $segmentCounts);
        }
        $blob = implode("\0", $subjects);
        $ints = pack('P*', ...$segmentCounts);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W55_FFI_CDEF.)
            $written = $ffi->nntmux_w552_nzb_parser_subject_plan_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, $out, $cap);
        } catch (\Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::planSubjectBatchPhp($subjects, $segmentCounts);
    }

    /**
     * Whether PCRE matches with its default (C) character tables, as the Wave 55 Rust matchers
     * assume: no byte outside ASCII `\w`/`\s` is classified as either, and ASCII letters fold only
     * to each other. An explicit setlocale() makes PHP rebuild the tables from LC_CTYPE (macOS
     * C.UTF-8 marks Latin-1 letters as `\w`; Turkish folds `i` to a high byte), so this probes PCRE
     * itself rather than the locale name.
     */
    public static function pcreUsesDefaultTables(): bool
    {
        static $probe = null;
        // Lower-case letters, then every byte outside ASCII `\w`/`\s`.
        $probe ??= 'abcdefghijklmnopqrstuvwxyz'.implode('', array_map(chr(...), [...range(0x00, 0x08), ...range(0x0E, 0x1F), ...range(0x21, 0x2F),
            ...range(0x3A, 0x40), ...range(0x5B, 0x5E), 0x60, ...range(0x7B, 0xFF)]));

        return preg_match('/\A[A-Z]{26}[^\w\s]*\z/i', $probe) === 1;
    }
}
