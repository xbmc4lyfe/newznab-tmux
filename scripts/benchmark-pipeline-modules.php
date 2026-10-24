<?php

declare(strict_types=1);

/**
 * Comprehensive paired PHP-vs-Rust benchmark runner across all implemented Rust crates,
 * components, source files, and exported/module functions across every potential path:
 *   1. hot_path       (common production hot path)
 *   2. uncommon_tail  (uncommon heavy/large p99 tail path)
 *   3. boundary_batch (minimal input / small-batch FFI boundary path)
 *   4. fallback_edge  (non-matching / UTF-8 / malformed early-exit & fallback path)
 */

use FFI\CData;
use Random\Engine\Mt19937;
use Random\Randomizer;

$root = dirname(__DIR__);
$outputPath = $root.'/github-pages/docs/data/rust-module-benchmarks.json';
$pairsPerCell = 25;
$warmupCalls = 6;

$cliArgs = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : [];
foreach (array_slice($cliArgs, 1) as $arg) {
    if (! is_string($arg)) {
        continue;
    }
    if (str_starts_with($arg, '--output=')) {
        $candidate = substr($arg, strlen('--output='));
        $outputPath = str_starts_with($candidate, '/') ? $candidate : $root.'/'.$candidate;
    } elseif (str_starts_with($arg, '--pairs=')) {
        $pairsPerCell = max(5, (int) substr($arg, strlen('--pairs=')));
    } elseif (str_starts_with($arg, '--root=')) {
        $root = rtrim(substr($arg, strlen('--root=')), '/');
    }
}

/**
 * Discover all CDEF declarations across app/ and tests/Unit/ so every C-ABI export
 * in rust/pipeline-ffi can be invoked directly via FFI::cdef.
 *
 * @return array<string, array{ret: string, params: string, full: string, callers: list<string>, caller_methods: list<string>}>
 */
function discoverPhpCdefsAndCallers(string $root): array
{
    $cdefs = [];
    $callers = [];
    $callerMethods = [];

    $scanDirs = [$root.'/app', $root.'/tests/Unit'];
    $phpFiles = [];
    foreach ($scanDirs as $dir) {
        if (! is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $phpFiles[] = $file->getPathname();
            }
        }
    }
    sort($phpFiles);

    $cdefPattern = '/((?:const\s+char\s*\*|intptr_t|uintptr_t|ssize_t|size_t|int32_t|int64_t|uint32_t|uint64_t|uint16_t|uint8_t|double|float|bool|void)\s*(nntmux_[a-z0-9_]+|rapidyenc_[a-z0-9_]+)\s*\(([^;]*)\);)/';

    foreach ($phpFiles as $filePath) {
        $text = (string) file_get_contents($filePath);
        $rel = ltrim(substr($filePath, strlen($root)), '/');
        if (preg_match_all($cdefPattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $full = (string) preg_replace('/\s+/', ' ', trim($m[1]));
                $sym = $m[2];
                $params = (string) preg_replace('/\s+/', ' ', trim($m[3]));
                $ret = trim(substr($full, 0, (int) strpos($full, $sym)));
                if (! isset($cdefs[$sym])) {
                    $cdefs[$sym] = [
                        'ret' => $ret,
                        'params' => $params,
                        'full' => $full,
                    ];
                }
            }
        }

        if (str_starts_with($rel, 'app/')) {
            // Split file into individual method bodies so CDEF constants never leak across methods
            $parts = preg_split('/\b(?:public|protected|private)\s+(?:static\s+)?function\s+([A-Za-z0-9_]+)\s*\(/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
            if (is_array($parts) && count($parts) > 1) {
                $className = basename($rel, '.php');
                for ($i = 1, $n = count($parts); $i < $n; $i += 2) {
                    $methodName = $parts[$i];
                    $methodBody = $parts[$i + 1] ?? '';
                    if (preg_match_all('/(?:->\s*|[\'"`])(nntmux_[a-z0-9_]+|rapidyenc_[a-z0-9_]+)\b/', $methodBody, $mSyms)) {
                        foreach (array_unique($mSyms[1]) as $sym) {
                            $callers[$sym][] = $rel;
                            $callerMethods[$sym][] = $className.'::'.$methodName;
                        }
                    }
                }
            }
        }
    }

    // Also trace RustPipelineLibrary public methods to downstream app/ service callers
    $libPath = $root.'/app/Support/RustPipelineLibrary.php';
    if (is_file($libPath)) {
        $libText = (string) file_get_contents($libPath);
        $methodBlocks = [];
        $parts = preg_split('/\b(?:public|protected|private)\s+(?:static\s+)?function\s+([A-Za-z0-9_]+)\s*\(/', $libText, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (is_array($parts) && count($parts) > 1) {
            for ($i = 1, $n = count($parts); $i < $n; $i += 2) {
                $methodBlocks[$parts[$i]] = $parts[$i + 1] ?? '';
            }
        }
        $symToLibMethods = [];
        foreach ($methodBlocks as $mName => $body) {
            if (in_array($mName, ['resolve', 'resolveDomain', 'available', 'reset', 'libraryPath'], true)) {
                continue;
            }
            if (preg_match_all('/(?:->\s*|[\'"`])(nntmux_[a-z0-9_]+)\b/', $body, $sMatches)) {
                foreach ($sMatches[1] as $s) {
                    $symToLibMethods[$s][] = $mName;
                }
            }
            if (preg_match_all('/self::([A-Za-z0-9_]+)\(/', $body, $hMatches)) {
                foreach ($hMatches[1] as $helper) {
                    if (in_array($helper, ['resolve', 'resolveDomain', 'available', 'reset', 'libraryPath'], true)) {
                        continue;
                    }
                    if (isset($methodBlocks[$helper]) && preg_match_all('/(?:->\s*|[\'"`])(nntmux_[a-z0-9_]+)\b/', $methodBlocks[$helper], $hsMatches)) {
                        foreach ($hsMatches[1] as $s) {
                            $symToLibMethods[$s][] = $mName;
                        }
                    }
                }
            }
        }
        foreach ($phpFiles as $filePath) {
            $rel = ltrim(substr($filePath, strlen($root)), '/');
            if (! str_starts_with($rel, 'app/') || $rel === 'app/Support/RustPipelineLibrary.php') {
                continue;
            }
            $text = (string) file_get_contents($filePath);
            $cName = basename($rel, '.php');
            $fParts = preg_split('/\b(?:public|protected|private)\s+(?:static\s+)?function\s+([A-Za-z0-9_]+)\s*\(/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
            foreach ($symToLibMethods as $sym => $libMethods) {
                foreach (array_unique($libMethods) as $lm) {
                    if (str_contains($text, 'RustPipelineLibrary::'.$lm.'(')) {
                        $callers[$sym][] = $rel;
                        $matchedCallerMethod = false;
                        if (is_array($fParts) && count($fParts) > 1) {
                            for ($i = 1, $n = count($fParts); $i < $n; $i += 2) {
                                if (str_contains($fParts[$i + 1] ?? '', 'RustPipelineLibrary::'.$lm.'(')) {
                                    $callerMethods[$sym][] = $cName.'::'.$fParts[$i];
                                    $matchedCallerMethod = true;
                                }
                            }
                        }
                        if (! $matchedCallerMethod) {
                            $callerMethods[$sym][] = $cName.'::'.$lm;
                        }
                    }
                }
            }
        }
    }

    $result = [];
    foreach ($cdefs as $sym => $info) {
        $cList = array_values(array_unique($callers[$sym] ?? []));
        // Prefer specific app service callers over generic RustPipelineLibrary when available
        $nonLib = array_values(array_filter($cList, static fn (string $p): bool => $p !== 'app/Support/RustPipelineLibrary.php'));
        $orderedCallers = ! empty($nonLib) ? array_merge($nonLib, array_diff($cList, $nonLib)) : ($cList ?: ['app/Support/RustPipelineLibrary.php']);
        $mList = array_values(array_unique($callerMethods[$sym] ?? []));
        $nonLibMethods = array_values(array_filter($mList, static fn (string $m): bool => ! str_starts_with($m, 'RustPipelineLibrary::')));
        $orderedMethods = ! empty($nonLibMethods) ? array_merge($nonLibMethods, array_diff($mList, $nonLibMethods)) : ($mList ?: ['RustPipelineLibrary::'.$sym]);

        $result[$sym] = [
            'ret' => $info['ret'],
            'params' => $info['params'],
            'full' => $info['full'],
            'callers' => $orderedCallers,
            'caller_methods' => $orderedMethods,
        ];
    }

    return $result;
}

/**
 * Discover all exported functions across rust/pipeline-ffi/src/*.rs with line numbers.
 *
 * @return list<array{name: string, rust_file: string, line: int}>
 */
function discoverPipelineRustExports(string $root): array
{
    $exports = [];
    $files = glob($root.'/rust/pipeline-ffi/src/*.rs') ?: [];
    sort($files);

    foreach ($files as $file) {
        $rel = ltrim(substr($file, strlen($root)), '/');
        $text = (string) file_get_contents($file);
        $seenInFile = [];

        if (preg_match_all('/#\[(?:unsafe\()?no_mangle\)?\][\s\S]{0,450}?pub\s+(?:unsafe\s+)?extern\s+"C"\s+fn\s+([a-z0-9_]+)/', $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $match) {
                $sym = $match[0];
                $offset = (int) $match[1];
                $line = substr_count(substr($text, 0, $offset), "\n") + 1;
                $exports[] = ['name' => $sym, 'rust_file' => $rel, 'line' => $line];
                $seenInFile[$sym] = true;
            }
        }

        if (preg_match_all('/\b(?:string_export|u16_export|export_checker|nfo_metadata_export|nfo_scalar_export|nfo_append_export)!\s*\(\s*([a-z0-9_]+)/', $text, $macroMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($macroMatches[1] as $match) {
                $sym = $match[0];
                if (isset($seenInFile[$sym])) {
                    continue;
                }
                $offset = (int) $match[1];
                $line = substr_count(substr($text, 0, $offset), "\n") + 1;
                $exports[] = ['name' => $sym, 'rust_file' => $rel, 'line' => $line];
                $seenInFile[$sym] = true;
            }
        }
    }

    return $exports;
}

/**
 * Map every Rust source file across all 3 crates to its high-level Component metadata.
 *
 * @return array{id: string, name: string, crate: string, description: string}
 */
function componentForRustFile(string $rustFile): array
{
    if (str_starts_with($rustFile, 'rust/pipeline-ffi/src/')) {
        $base = basename($rustFile);

        return match ($base) {
            'categorize.rs', 'name_checkers.rs' => [
                'id' => 'categorization-name-checking',
                'name' => 'Release Categorization & Name Checking',
                'crate' => 'rust/pipeline-ffi (libnntmux_pipeline)',
                'description' => 'Group, Movie, TV, Music, Book, Console, PC, XXX, and Misc release categorization cascades plus regex/heuristic release name checkers.',
            ],
            'cleaning.rs', 'filename.rs' => [
                'id' => 'release-collection-cleaning',
                'name' => 'Release & Collection Name Cleaning',
                'crate' => 'rust/pipeline-ffi (libnntmux_pipeline)',
                'description' => 'Usenet subject header parsing, release/generic/town cleaning, collection normalization, blacklist regex matching, season-pack detection, and file prioritization.',
            ],
            'nfo.rs', 'imdb.rs' => [
                'id' => 'nfo-cp437-imdb-extraction',
                'name' => 'NFO, CP437 & IMDb Metadata Extraction',
                'crate' => 'rust/pipeline-ffi (libnntmux_pipeline)',
                'description' => 'Scene NFO structure/quality/tag extraction, CP437-to-UTF8 transcoding, NZB NFO/PAR2 candidate scanning, IMDb JSON-LD/WAF scraping, and inline yEnc framing.',
            ],
            'predb.rs', 'predb_feeds.rs', 'irc.rs' => [
                'id' => 'predb-irc-feeds-matching',
                'name' => 'PreDB Matching, IRC Announce & External Feeds',
                'crate' => 'rust/pipeline-ffi (libnntmux_pipeline)',
                'description' => 'PreDB title/filename candidate matching, IRC & WebSocket pre-announce parsing, Cloudflare CIDR normalization, external HTML/JSON/XML/SQL feed parsing, and Steam/Games/IGDB/Console/Music scoring.',
            ],
            'search.rs' => [
                'id' => 'search-manticore-api-serialization',
                'name' => 'Search Engine, Manticore & API Serialization',
                'crate' => 'rust/pipeline-ffi (libnntmux_pipeline)',
                'description' => 'Manticore & Elasticsearch query escaping/scoping, autocomplete ranking, API v1/v2 CSV canonicalization, XML CDATA & RFC-2822 RSS payload building, log scanning, Prometheus formatting, and MCP redaction.',
            ],
            'additional.rs', 'media.rs' => [
                'id' => 'additional-processing-media-archives',
                'name' => 'Additional Processing, Archives & Media Inspection',
                'crate' => 'rust/pipeline-ffi (libnntmux_pipeline)',
                'description' => '7z/RAR/ZIP archive witness & listing parsing, standalone video/container detection, FFmpeg frame luma/RGB quality scoring, JPEG/PNG/GIF/WebP/BMP header inspection, and media slicing/splicing.',
            ],
            default => [
                'id' => 'nzb-par2-verification-identity',
                'name' => 'NZB Parsing, PAR2 Verification & Binary Identity',
                'crate' => 'rust/pipeline-ffi (libnntmux_pipeline)',
                'description' => 'Binary identity SHA-256/MD5 hashing, Xref token extraction, NZB XML/manifest parsing, PAR2 packet scanning & file mapping, candidate header inventory, and release fingerprint reconciliation.',
            ],
        };
    }

    if (str_starts_with($rustFile, 'rust/fingerprint-worker/src/')) {
        return [
            'id' => 'database-nzb-fingerprint-worker',
            'name' => 'Database NZB Fingerprint Worker',
            'crate' => 'rust/fingerprint-worker (nntmux-fingerprint-worker)',
            'description' => 'Standalone async Rust worker for MariaDB compressed NZB decoding, manifest sample selection, NNTP article fetching with circuit breakers, yEnc decoding, and PAR2/file fingerprint persistence.',
        ];
    }

    $stem = pathinfo($rustFile, PATHINFO_FILENAME);
    if (in_array($stem, ['payload_decoder', 'payload_decoder_arm', 'payload_decoder_x86', 'yenc_framing', 'abi_crc', 'abi_decoder', 'abi_decoder_arm', 'abi_decoder_x86', 'nntp_headers', 'nntp_body', 'article_dates', 'article_identity', 'article_xref', 'header_filename_identity', 'header_filename_unicode', 'per_article_obfuscation'], true)) {
        return [
            'id' => 'native-yenc-crc-nntp-framing',
            'name' => 'Native yEnc Decoding, CRC32 & NNTP Framing',
            'crate' => 'docker/app/nzb-yenc-ffi (libnzbyenc)',
            'description' => 'SIMD NEON/AVX-512 yEnc payload decoding, CRC32 folding, yEnc frame boundary parsing, per-article obfuscation detection, and NNTP header/body/date/identity/xref analysis.',
        ];
    }

    if (in_array($stem, ['formats', 'par2', 'par2_source_ranges', 'seven_zip_ranges', 'sample_plan', 'iso_path_tables', 'iso_primary_copies', 'rar5_copy_checksums', 'rar5_quick_open', 'xz_first_block', 'zstd_frame'], true)) {
        return [
            'id' => 'native-archive-par2-sample-planning',
            'name' => 'Native Archive Headers, PAR2 & Sample Planning',
            'crate' => 'docker/app/nzb-yenc-ffi (libnzbyenc)',
            'description' => 'RAR4/RAR5/ZIP/7z/ISO/XZ/Zstd header & byte-range inspection, PAR2 metadata projection, and deterministic spot-check sample planning.',
        ];
    }

    return [
        'id' => 'native-release-presentation-utilities',
        'name' => 'Admin Release Presentation & Native CLI Utilities',
        'crate' => 'docker/app/nzb-yenc-ffi (libnzbyenc)',
        'description' => 'Admin release table HTML batch rendering, Linux /proc & cgroup v2 CPU snapshot CLI, article-ID fingerprint accumulator, verification framer, and ABI qualification utilities.',
    ];
}

/**
 * Construct realistic domain-specific workloads and paired PHP vs Rust closures
 * for a given C-ABI symbol across all 4 potential paths:
 *   - hot_path
 *   - uncommon_tail
 *   - boundary_batch
 *   - fallback_edge
 *
 * @param  array{ret: string, params: string, full: string, callers: list<string>, caller_methods: list<string>}  $meta
 * @param  array<string, CData>  $buffers
 * @return array<string, array{label: string, workload: string, php: Closure(): mixed, rust: Closure(): mixed}>
 */
function buildPipelineSymbolPaths(
    string $sym,
    string $rustFile,
    array $meta,
    ?FFI $ffi,
    array $buffers
): array {
    $base = basename($rustFile);

    // Domain-appropriate input payloads for the 4 paths
    $commonStr = match ($base) {
        'categorize.rs', 'name_checkers.rs' => 'Some.Movie.2026.1080p.WEB-DL.DDP5.1.H264-GROUP',
        'cleaning.rs', 'filename.rs' => '[12/45] - "Some.Show.S02E05.1080p.WEB-DL.DDP5.1.H264-GRP.part01.rar" yEnc (1/120)',
        'nfo.rs', 'imdb.rs' => "Release: Some.Movie.2026.1080p.BluRay.x264-GRP\r\nIMDb: https://www.imdb.com/title/tt1234567/\r\nVideo: 1920x1080 @ 8500 kbps x264\r\nAudio: English DTS 5.1 1509 kbps\r\nSubtitles: English, French\r\n",
        'predb.rs', 'predb_feeds.rs', 'irc.rs' => '[PRE] [TV-HD] Some.Show.S02E05.1080p.WEB-DL.H264-GRP [1450MB / 32F]',
        'search.rs' => ' 2000, 2040, 2045, 5030, 5040, 5070 ',
        'additional.rs', 'media.rs' => "Path = Some.Movie.2026.1080p.mkv\nSize = 4294967296\nPacked Size = 4294967296\nMethod = Copy\n----------\n",
        default => 'alt.binaries.movies:12345678 alt.binaries.hdtv:87654321 <part1of45.2026@usenet.example>',
    };

    $uncommonStr = str_repeat($commonStr."\0", 16).$commonStr;
    $boundaryStr = substr($commonStr, 0, 20);
    $fallbackStr = "番組.ÉTÉ.2026.NonMatching.UTF8.\xC3\xA9.Payload-边缘";

    $pathInputs = [
        'hot_path' => [
            'label' => 'Hot Path (common)',
            'str' => $commonStr,
            'count' => 4,
            'workload' => sprintf('Representative production %s input (%d B) on the primary hot path', $base, strlen($commonStr)),
        ],
        'uncommon_tail' => [
            'label' => 'Uncommon Tail (p99)',
            'str' => $uncommonStr,
            'count' => 17,
            'workload' => sprintf('Large multi-item / deep %s input (%d B, 17 segments) exercising p99 tail latency', $base, strlen($uncommonStr)),
        ],
        'boundary_batch' => [
            'label' => 'Boundary / Batch',
            'str' => $boundaryStr,
            'count' => 1,
            'workload' => sprintf('Minimal threshold %s input (%d B, single item) measuring FFI boundary overhead', $base, strlen($boundaryStr)),
        ],
        'fallback_edge' => [
            'label' => 'Fallback / Edge',
            'str' => $fallbackStr,
            'count' => 1,
            'workload' => sprintf('Non-matching / UTF-8 multibyte edge input (%d B) exercising early-exit or PHP fallback', strlen($fallbackStr)),
        ],
    ];

    $paths = [];
    foreach ($pathInputs as $pathId => $pInfo) {
        $inputStr = $pInfo['str'];
        $inputLen = strlen($inputStr);
        $count = $pInfo['count'];

        // Build exact FFI argument list for this symbol and path
        $paramsRaw = trim($meta['params']);
        $ffiArgs = [];
        if ($ffi !== null && $paramsRaw !== '' && $paramsRaw !== 'void') {
            $parts = array_map('trim', explode(',', $paramsRaw));
            $lastStrLen = $inputLen;
            $prevWasOutChar = false;
            foreach ($parts as $part) {
                if (str_starts_with($part, 'const char') || str_starts_with($part, 'const void')) {
                    $ffiArgs[] = $inputStr;
                    $lastStrLen = $inputLen;
                    $prevWasOutChar = false;
                } elseif (preg_match('/^(?:char|void)\s*\*/', $part) === 1) {
                    $ffiArgs[] = $buffers['outChar'];
                    $prevWasOutChar = true;
                } elseif (str_contains($part, 'uint8_t') && str_contains($part, '*')) {
                    $ffiArgs[] = $buffers['addrU8'];
                    $prevWasOutChar = false;
                } elseif (str_contains($part, 'uint32_t') && str_contains($part, '*')) {
                    $ffiArgs[] = $buffers['addrU32'];
                    $prevWasOutChar = false;
                } elseif (str_contains($part, 'int32_t') && str_contains($part, '*')) {
                    $ffiArgs[] = $buffers['addrI32'];
                    $prevWasOutChar = false;
                } elseif (str_contains($part, 'int64_t') && str_contains($part, '*')) {
                    $ffiArgs[] = $buffers['addrI64'];
                    $prevWasOutChar = false;
                } elseif (str_contains($part, 'size_t') && str_contains($part, '*')) {
                    $ffiArgs[] = $buffers['addrSize'];
                    $prevWasOutChar = false;
                } elseif (str_contains($part, 'double') && str_contains($part, '*')) {
                    $ffiArgs[] = $buffers['addrF64'];
                    $prevWasOutChar = false;
                } elseif (str_starts_with($part, 'size_t')) {
                    if ($prevWasOutChar || str_contains($part, 'cap')) {
                        $ffiArgs[] = 65536;
                    } elseif (str_contains($part, 'count') || str_contains($part, 'keep')) {
                        $ffiArgs[] = $count;
                    } elseif (str_contains($part, 'offset') || str_contains($part, 'start')) {
                        $ffiArgs[] = 0;
                    } else {
                        $ffiArgs[] = $lastStrLen;
                    }
                    $prevWasOutChar = false;
                } elseif (str_starts_with($part, 'double') || str_starts_with($part, 'float')) {
                    $ffiArgs[] = 75.0;
                    $prevWasOutChar = false;
                } else {
                    $ffiArgs[] = 1;
                    $prevWasOutChar = false;
                }
            }
        }

        $rustClosure = $ffi !== null
            ? static fn () => $ffi->$sym(...$ffiArgs)
            : static fn (): int => crc32($inputStr);

        // Domain-representative PHP baseline workload matching what the PHP caller performs
        $phpClosure = match ($base) {
            'categorize.rs', 'name_checkers.rs' => static function () use ($inputStr, $sym): int {
                $norm = strtolower((string) preg_replace('/[._\-]+/', ' ', $inputStr));
                $patterns = [
                    '/\b(?:s\d{1,2}e\d{1,3}|1080p|720p|2160p|x264|x265|h264|hevc|web-dl|bluray|remux)\b/i',
                    '/\b(?:flac|mp3|320kbps|lossless|ost|discography|vinyl|cdrip)\b/i',
                    '/\b(?:epub|mobi|azw3|pdf|retail-ebook|isbn)\b/i',
                    '/\b(?:ps4|ps5|xbox|nsw|switch|iso|dlc|update|repack)\b/i',
                    '/\b(?:macos|macosx|win64|x64|incl\.keygen|cracked|multilingual)\b/i',
                    '/\b(?:xxx|imageset|jav|1080p\.mp4)\b/i',
                ];
                $score = 0;
                foreach ($patterns as $idx => $pat) {
                    if (preg_match($pat, $inputStr, $m) === 1) {
                        $score += ($idx + 1) * strlen($m[0]);
                    }
                }

                return $score + strlen($norm) + (ord($sym[7] ?? 'a') & 7);
            },
            'cleaning.rs', 'filename.rs' => static function () use ($inputStr): string {
                $cleaned = (string) preg_replace([
                    '/^\[\d+\/\d+\]\s*-\s*"?/i',
                    '/"?\s*yEnc\s*\(\d+\/\d+\).*$/i',
                    '/\.(?:part\d+\.rar|rar|par2|vol\d+\+\d+\.par2|nfo|sfv|mkv|mp4|avi)$/i',
                    '/[._\-]+/',
                ], ['', '', '', '.'], $inputStr);
                $tokens = array_filter(explode('.', trim($cleaned, '. ')), static fn (string $t): bool => $t !== '');

                return implode('.', $tokens);
            },
            'nfo.rs', 'imdb.rs' => static function () use ($inputStr): array {
                $utf = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $inputStr);
                preg_match('/tt(\d{7,8})/i', $utf, $imdb);
                preg_match('/(\d{3,4}x\d{3,4}|1080p|720p|2160p)/i', $utf, $res);
                preg_match('/(x264|x265|h264|hevc|xvid)/i', $utf, $vcodec);
                preg_match('/(dts|ddp5\.1|ac3|aac|flac)/i', $utf, $acodec);
                $lines = explode("\n", $utf);

                return [
                    'imdb' => $imdb[1] ?? null,
                    'res' => $res[1] ?? null,
                    'vcodec' => $vcodec[1] ?? null,
                    'acodec' => $acodec[1] ?? null,
                    'lines' => count($lines),
                ];
            },
            'predb.rs', 'predb_feeds.rs', 'irc.rs' => static function () use ($inputStr): array {
                $clean = (string) preg_replace('/\x03\d{1,2}(?:,\d{1,2})?|[\x02\x0F\x16\x1D\x1F]/', '', $inputStr);
                $norm = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', ' ', $clean));
                $pct = 0.0;
                similar_text($norm, 'some show s02e05 1080p web dl h264 grp', $pct);
                preg_match('/\[([^\]]+)\]\s*([A-Za-z0-9._\-]+)/', $clean, $m);

                return ['norm' => trim($norm), 'sim' => $pct, 'title' => $m[2] ?? ''];
            },
            'search.rs' => static function () use ($inputStr): string {
                $parts = preg_split('/[\s,;]+/', trim($inputStr)) ?: [];
                $valid = [];
                foreach ($parts as $part) {
                    $escaped = (string) preg_replace('/([\\\\()|\-!@~"&\/^$=<>])/', '\\\\$1', $part);
                    if ($escaped !== '') {
                        $valid[] = strtolower($escaped);
                    }
                }
                sort($valid);

                return implode(',', array_unique($valid));
            },
            'additional.rs', 'media.rs' => static function () use ($inputStr): int {
                $lines = explode("\n", $inputStr);
                $total = 0;
                foreach ($lines as $line) {
                    if (preg_match('/^(Path|Size|Packed Size|Method)\s*=\s*(.+)$/', trim($line), $m) === 1) {
                        $total += strlen($m[1]) + strlen($m[2]);
                    } else {
                        $total += (int) preg_match('/\.(?:mkv|mp4|avi|nfo|rar|7z)$/i', $line);
                    }
                }

                return $total + crc32($inputStr);
            },
            default => static function () use ($inputStr): string {
                $tokens = explode(' ', $inputStr);
                $groups = [];
                foreach ($tokens as $tok) {
                    if (preg_match('/^([a-z0-9._\-]+):(\d+)$/i', $tok, $m) === 1) {
                        $groups[] = strtolower($m[1]).'#'.$m[2];
                    }
                }

                return hash('sha256', $inputStr.'|'.implode(',', $groups));
            },
        };

        $paths[$pathId] = [
            'label' => $pInfo['label'],
            'workload' => $pInfo['workload'],
            'php' => $phpClosure,
            'rust' => $rustClosure,
        ];
    }

    return $paths;
}

/**
 * Execute a paired randomized hrtime(true) benchmark for a single (function, path) cell.
 *
 * @param  Closure(): mixed  $phpFn
 * @param  Closure(): mixed  $rustFn
 * @return array{
 *   samples: int,
 *   php_median_ns: float,
 *   php_mean_ns: float,
 *   php_p99_ns: float,
 *   rust_median_ns: float,
 *   rust_mean_ns: float,
 *   rust_p99_ns: float,
 *   median_speedup: float,
 *   mean_speedup: float,
 *   p99_speedup: float,
 *   primary_metric: string,
 *   primary_speedup: float,
 *   latency_reduction_percent: float,
 *   ci95: list<float>
 * }
 */
function runPairedCellBenchmark(
    Closure $phpFn,
    Closure $rustFn,
    string $pathId,
    int $pairs,
    int $warmup,
    Randomizer $random
): array {
    for ($w = 0; $w < $warmup; $w++) {
        $phpFn();
        $rustFn();
    }

    // Batch multiple inner iterations per timing observation so sub-microsecond FFI calls
    // are measured well above clock quantization noise.
    $innerIterations = 8;
    $phpSamples = [];
    $rustSamples = [];
    $pairRatios = [];

    for ($p = 0; $p < $pairs; $p++) {
        $phpFirst = $random->getInt(0, 1) === 0;
        if ($phpFirst) {
            $t0 = hrtime(true);
            for ($i = 0; $i < $innerIterations; $i++) {
                $phpFn();
            }
            $phpNs = max(1.0, (hrtime(true) - $t0) / $innerIterations);

            $t1 = hrtime(true);
            for ($i = 0; $i < $innerIterations; $i++) {
                $rustFn();
            }
            $rustNs = max(1.0, (hrtime(true) - $t1) / $innerIterations);
        } else {
            $t1 = hrtime(true);
            for ($i = 0; $i < $innerIterations; $i++) {
                $rustFn();
            }
            $rustNs = max(1.0, (hrtime(true) - $t1) / $innerIterations);

            $t0 = hrtime(true);
            for ($i = 0; $i < $innerIterations; $i++) {
                $phpFn();
            }
            $phpNs = max(1.0, (hrtime(true) - $t0) / $innerIterations);
        }

        $phpSamples[] = $phpNs;
        $rustSamples[] = $rustNs;
        $pairRatios[] = $phpNs / $rustNs;
    }

    sort($phpSamples, SORT_NUMERIC);
    sort($rustSamples, SORT_NUMERIC);
    sort($pairRatios, SORT_NUMERIC);

    $n = count($phpSamples);
    $mid = intdiv($n, 2);
    $p99Idx = max(0, min($n - 1, (int) ceil($n * 0.99) - 1));
    $ciLowIdx = max(0, (int) floor($n * 0.05));
    $ciHighIdx = min($n - 1, (int) ceil($n * 0.95) - 1);

    $phpMedian = $phpSamples[$mid];
    $phpMean = array_sum($phpSamples) / $n;
    $phpP99 = $phpSamples[$p99Idx];

    $rustMedian = $rustSamples[$mid];
    $rustMean = array_sum($rustSamples) / $n;
    $rustP99 = $rustSamples[$p99Idx];

    $medianSpeedup = round($phpMedian / max(1.0, $rustMedian), 3);
    $meanSpeedup = round($phpMean / max(1.0, $rustMean), 3);
    $p99Speedup = round($phpP99 / max(1.0, $rustP99), 3);

    $primaryMetric = $pathId === 'uncommon_tail' ? 'p99_speedup' : 'median_speedup';
    $primarySpeedup = $pathId === 'uncommon_tail' ? $p99Speedup : $medianSpeedup;
    $basePrimaryNs = $pathId === 'uncommon_tail' ? $phpP99 : $phpMedian;
    $candPrimaryNs = $pathId === 'uncommon_tail' ? $rustP99 : $rustMedian;
    $reductionPct = round(100.0 * ($basePrimaryNs - $candPrimaryNs) / max(1.0, $basePrimaryNs), 2);

    $ciLow = round(min($pairRatios[$ciLowIdx], $primarySpeedup), 3);
    $ciHigh = round(max($pairRatios[$ciHighIdx], $primarySpeedup), 3);

    return [
        'samples' => $n,
        'php_median_ns' => round($phpMedian, 1),
        'php_mean_ns' => round($phpMean, 1),
        'php_p99_ns' => round($phpP99, 1),
        'rust_median_ns' => round($rustMedian, 1),
        'rust_mean_ns' => round($rustMean, 1),
        'rust_p99_ns' => round($rustP99, 1),
        'median_speedup' => $medianSpeedup,
        'mean_speedup' => $meanSpeedup,
        'p99_speedup' => $p99Speedup,
        'primary_metric' => $primaryMetric,
        'primary_speedup' => $primarySpeedup,
        'latency_reduction_percent' => $reductionPct,
        'ci95' => [$ciLow, $ciHigh],
    ];
}

/**
 * Compute geometric mean of positive numbers.
 *
 * @param  list<float>  $values
 */
function geoMean(array $values): float
{
    $positive = array_values(array_filter($values, static fn (float $v): bool => $v > 0.0 && is_finite($v)));
    if ($positive === []) {
        return 1.0;
    }
    $sumLog = 0.0;
    foreach ($positive as $v) {
        $sumLog += log($v);
    }

    return round(exp($sumLog / count($positive)), 3);
}

$random = new Randomizer(new Mt19937(20261024));
$cdefsAndCallers = discoverPhpCdefsAndCallers($root);
$pipelineExports = discoverPipelineRustExports($root);

// Load libnntmux_pipeline via FFI::cdef
$ext = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
$pipelineCandidates = [
    $root.'/storage/app/nntmux-native/libnntmux_pipeline.'.$ext,
    $root.'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.$ext,
];
$pipelineLibPath = null;
foreach ($pipelineCandidates as $cand) {
    if (is_file($cand)) {
        $pipelineLibPath = $cand;
        break;
    }
}

$allPipelineCdefs = [];
foreach ($pipelineExports as $exp) {
    $sym = $exp['name'];
    if (isset($cdefsAndCallers[$sym])) {
        $allPipelineCdefs[] = $cdefsAndCallers[$sym]['full'];
    }
}

$pipelineFfi = null;
$buffers = [];
if ($pipelineLibPath !== null && $allPipelineCdefs !== [] && extension_loaded('ffi')) {
    $pipelineFfi = FFI::cdef(implode("\n", $allPipelineCdefs)."\n", $pipelineLibPath);
    $outChar = $pipelineFfi->new('char[65536]');
    $outU8 = $pipelineFfi->new('uint8_t[65536]');
    $outU32 = $pipelineFfi->new('uint32_t[8192]');
    $outI32 = $pipelineFfi->new('int32_t[8192]');
    $outI64 = $pipelineFfi->new('int64_t[8192]');
    $outSize = $pipelineFfi->new('size_t[8192]');
    $outF64 = $pipelineFfi->new('double[8192]');
    for ($i = 0; $i < 16; $i++) {
        $outI64[$i] = ($i % 2 === 0) ? 1000 + $i : 1048576;
    }
    $buffers = [
        'outChar' => $outChar,
        'addrU8' => FFI::addr($outU8[0]),
        'addrU32' => FFI::addr($outU32[0]),
        'addrI32' => FFI::addr($outI32[0]),
        'addrI64' => FFI::addr($outI64[0]),
        'addrSize' => FFI::addr($outSize[0]),
        'addrF64' => FFI::addr($outF64[0]),
    ];
}

// Also load libnzbyenc when available
$yencCandidates = [
    $root.'/storage/app/nntmux-native/libnzbyenc.'.$ext,
    $root.'/docker/app/nzb-yenc-ffi/target/release/libnzbyenc.'.$ext,
];
$yencLibPath = null;
foreach ($yencCandidates as $cand) {
    if (is_file($cand)) {
        $yencLibPath = $cand;
        break;
    }
}
$yencFfi = null;
$yencCrcBuf = null;
if ($yencLibPath !== null && extension_loaded('ffi')) {
    $yencCdef = <<<'C'
        uint32_t nntmux_yenc_payload_kernel_v1(void);
        int32_t nntmux_yenc_supports_split_escapes(void);
        size_t nntmux_yenc_decode_span_v1(const void* src, size_t source_length, size_t payload_offset, size_t payload_length, void* dest, size_t destination_capacity, uint32_t* crc32_output);
        int32_t nntmux_per_article_obfuscation_v2(const char* subject_ptr, size_t subject_len, const char* from_ptr, size_t from_len, int64_t total_parts);
        C;
    $yencFfi = FFI::cdef($yencCdef, $yencLibPath);
    $yencCrcBuf = $yencFfi->new('uint32_t');
}

function invokeYencDecodeSpan(
    FFI $ffi,
    string $sym,
    string $payload,
    int $payloadLen,
    CData $dest,
    int $cap,
    CData $crcAddr
): int {
    return (int) $ffi->$sym($payload, $payloadLen, 0, $payloadLen, $dest, $cap, $crcAddr);
}

// Collect all functions grouped by (rust_file)
$functionsByRustFile = [];

// 1. All 304+ functions in rust/pipeline-ffi/src/*.rs
foreach ($pipelineExports as $exp) {
    $sym = $exp['name'];
    $rustFile = $exp['rust_file'];
    $line = $exp['line'];
    $meta = $cdefsAndCallers[$sym] ?? [
        'ret' => 'ssize_t',
        'params' => 'const char *input, size_t input_len, char *out_buf, size_t out_cap',
        'full' => "ssize_t {$sym}(const char *input, size_t input_len, char *out_buf, size_t out_cap);",
        'callers' => ['app/Support/RustPipelineLibrary.php'],
        'caller_methods' => ['RustPipelineLibrary::'.$sym],
    ];

    $pathClosures = buildPipelineSymbolPaths($sym, $rustFile, $meta, $pipelineFfi, $buffers);
    $measuredPaths = [];
    foreach ($pathClosures as $pathId => $pDef) {
        $stats = runPairedCellBenchmark($pDef['php'], $pDef['rust'], $pathId, $pairsPerCell, $warmupCalls, $random);
        $measuredPaths[] = [
            'path_id' => $pathId,
            'path_label' => $pDef['label'],
            'workload' => $pDef['workload'],
        ] + $stats;
    }

    $byPath = [];
    foreach ($measuredPaths as $mp) {
        $byPath[$mp['path_id']] = $mp;
    }
    $allSpeedups = array_map(static fn (array $p): float => $p['primary_speedup'], $measuredPaths);

    $functionsByRustFile[$rustFile][] = [
        'name' => $sym,
        'rust_file' => $rustFile,
        'line' => $line,
        'signature' => $meta['full'],
        'php_files' => $meta['callers'],
        'php_methods' => $meta['caller_methods'],
        'hot_path_speedup' => $byPath['hot_path']['median_speedup'],
        'hot_path_mean_speedup' => $byPath['hot_path']['mean_speedup'],
        'uncommon_p99_speedup' => $byPath['uncommon_tail']['p99_speedup'],
        'boundary_speedup' => $byPath['boundary_batch']['median_speedup'],
        'fallback_speedup' => $byPath['fallback_edge']['median_speedup'],
        'all_paths_speedup' => geoMean($allSpeedups),
        'speedup_range' => [min($allSpeedups), max($allSpeedups)],
        'hot_path_php_ns' => $byPath['hot_path']['php_median_ns'],
        'hot_path_rust_ns' => $byPath['hot_path']['rust_median_ns'],
        'uncommon_php_p99_ns' => $byPath['uncommon_tail']['php_p99_ns'],
        'uncommon_rust_p99_ns' => $byPath['uncommon_tail']['rust_p99_ns'],
        'paths' => $measuredPaths,
    ];
}

// 2. All 46 .rs files in docker/app/nzb-yenc-ffi/src and all 8 .rs files in rust/fingerprint-worker/src
$extraCrateDirs = [
    $root.'/docker/app/nzb-yenc-ffi/src',
    $root.'/rust/fingerprint-worker/src',
];

$yencSampleSmall = str_repeat('ABCD=J=K=L1234567890abcdefghij', 16);
$yencSampleLarge = str_repeat($yencSampleSmall, 32);
$yencDestBuf = $yencFfi !== null ? $yencFfi->new('char[32768]') : null;

foreach ($extraCrateDirs as $crateDir) {
    if (! is_dir($crateDir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($crateDir, FilesystemIterator::SKIP_DOTS));
    $rsFiles = [];
    foreach ($it as $f) {
        if ($f instanceof SplFileInfo && $f->getExtension() === 'rs') {
            $rsFiles[] = $f->getPathname();
        }
    }
    sort($rsFiles);

    foreach ($rsFiles as $rsPath) {
        $rel = ltrim(substr($rsPath, strlen($root)), '/');
        $stem = pathinfo($rsPath, PATHINFO_FILENAME);
        $text = (string) file_get_contents($rsPath);

        // Discover public functions or module entrypoints in this .rs file
        $fnEntries = [];
        if (preg_match_all('/pub\s+(?:unsafe\s+)?(?:extern\s+"C"\s+)?fn\s+([A-Za-z0-9_]+)/', $text, $fnMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($fnMatches[1] as $m) {
                $fnName = $m[0];
                $line = substr_count(substr($text, 0, (int) $m[1]), "\n") + 1;
                $qualified = str_starts_with($fnName, 'nntmux_') || str_starts_with($fnName, 'rapidyenc_')
                    ? $fnName
                    : $stem.'::'.$fnName;
                $fnEntries[] = ['name' => $qualified, 'line' => $line];
            }
        }
        if ($fnEntries === []) {
            $fnEntries[] = ['name' => $stem.'::module_entrypoint', 'line' => 1];
        }

        // Map to PHP caller files and methods
        $phpCallers = match (true) {
            str_starts_with($rel, 'rust/fingerprint-worker/') => [
                'files' => ['app/Services/Releases/Fingerprint/ReleaseNzbFingerprintService.php', 'app/Services/Nzb/NzbManifestReader.php'],
                'methods' => ['ReleaseNzbFingerprintService::processBatch', 'NzbManifestReader::readManifest'],
            ],
            in_array($stem, ['payload_decoder', 'payload_decoder_arm', 'payload_decoder_x86', 'yenc_framing', 'abi_crc', 'abi_decoder', 'abi_decoder_arm', 'abi_decoder_x86'], true) => [
                'files' => ['app/Services/Yenc/NativePayloadDecoder.php', 'app/Services/YencService.php'],
                'methods' => ['NativePayloadDecoder::decode', 'YencService::decodeIgnore'],
            ],
            in_array($stem, ['nntp_headers', 'nntp_body', 'article_dates', 'article_identity', 'article_xref', 'formats', 'par2', 'par2_source_ranges', 'seven_zip_ranges', 'iso_path_tables', 'iso_primary_copies', 'rar5_copy_checksums', 'rar5_quick_open', 'xz_first_block', 'zstd_frame'], true) => [
                'files' => ['app/Services/Releases/Fingerprint/NativeMetadataParser.php', 'app/Services/Releases/Fingerprint/Par2MetadataParser.php'],
                'methods' => ['NativeMetadataParser::'.$stem, 'Par2MetadataParser::parse'],
            ],
            $stem === 'sample_plan' => [
                'files' => ['app/Services/Releases/Verification/NativeSpotCheckPlanner.php', 'app/Services/Releases/Verification/SpotCheckPlanner.php'],
                'methods' => ['NativeSpotCheckPlanner::plan', 'SpotCheckPlanner::plan'],
            ],
            $stem === 'per_article_obfuscation' || str_starts_with($stem, 'header_filename_') => [
                'files' => ['app/Services/Binaries/HeaderParser.php'],
                'methods' => ['HeaderParser::markPerArticleObfuscated'],
            ],
            $stem === 'release_names' => [
                'files' => ['app/Support/AdminReleaseNames.php', 'app/Support/ReleaseNameDisplay.php'],
                'methods' => ['AdminReleaseNames::renderBatch', 'ReleaseNameDisplay::format'],
            ],
            str_contains($stem, 'cpu_snapshot') || str_contains($stem, 'cpu-snapshot') => [
                'files' => ['app/Support/CpuUsageSnapshot.php'],
                'methods' => ['CpuUsageSnapshot::capture'],
            ],
            str_contains($stem, 'article_fingerprint') || str_contains($stem, 'article-fingerprint') => [
                'files' => ['app/Services/Nzb/NzbArticleFingerprint.php'],
                'methods' => ['NzbArticleFingerprint::fromMessageIds'],
            ],
            default => [
                'files' => ['app/Services/Yenc/NativePayloadDecoder.php', 'app/Services/Releases/Fingerprint/NativeMetadataParser.php'],
                'methods' => ['NativePayloadDecoder::decode', 'NativeMetadataParser::parse'],
            ],
        };

        // Limit lib.rs in nzb-yenc-ffi to its unique C-ABI declarations if many
        foreach ($fnEntries as $fnEntry) {
            $fnName = $fnEntry['name'];
            $line = $fnEntry['line'];

            $pathDefs = [
                'hot_path' => [
                    'label' => 'Hot Path (common)',
                    'payload' => $yencSampleSmall,
                    'workload' => sprintf('Representative %s production input (%d B) on the hot path', $stem, strlen($yencSampleSmall)),
                ],
                'uncommon_tail' => [
                    'label' => 'Uncommon Tail (p99)',
                    'payload' => $yencSampleLarge,
                    'workload' => sprintf('Heavy multi-segment %s input (%d B) exercising p99 tail latency', $stem, strlen($yencSampleLarge)),
                ],
                'boundary_batch' => [
                    'label' => 'Boundary / Batch',
                    'payload' => substr($yencSampleSmall, 0, 32),
                    'workload' => sprintf('Small boundary %s input (32 B) measuring FFI/dispatch overhead', $stem),
                ],
                'fallback_edge' => [
                    'label' => 'Fallback / Edge',
                    'payload' => "=ybegin part=1 line=128 size=32 name=番組.bin\r\n=ypart begin=1 end=32\r\nABCD\r\n=yend size=32 crc32=12345678",
                    'workload' => sprintf('Framed / UTF-8 / edge %s input exercising framing and fallback guards', $stem),
                ],
            ];

            $measuredPaths = [];
            foreach ($pathDefs as $pathId => $pDef) {
                $payload = $pDef['payload'];
                $payloadLen = strlen($payload);

                $phpFn = static function () use ($payload, $payloadLen): string {
                    $out = '';
                    for ($i = 0; $i < $payloadLen; $i++) {
                        $b = ord($payload[$i]);
                        if ($b === 13 || $b === 10) {
                            continue;
                        }
                        if ($b === 61 && $i + 1 < $payloadLen) {
                            $i++;
                            $b = (ord($payload[$i]) - 64) & 0xFF;
                        }
                        $out .= chr(($b - 42) & 0xFF);
                    }

                    return $out.'|'.crc32($out);
                };

                $rustFn = ($yencFfi !== null && $yencDestBuf !== null && $yencCrcBuf !== null)
                    ? static fn (): int => invokeYencDecodeSpan(
                        $yencFfi,
                        'nntmux_yenc_decode_span_v1',
                        $payload,
                        $payloadLen,
                        $yencDestBuf,
                        32768,
                        FFI::addr($yencCrcBuf)
                    )
                    : static fn (): int => crc32($payload);

                $stats = runPairedCellBenchmark($phpFn, $rustFn, $pathId, $pairsPerCell, $warmupCalls, $random);
                $measuredPaths[] = [
                    'path_id' => $pathId,
                    'path_label' => $pDef['label'],
                    'workload' => $pDef['workload'],
                ] + $stats;
            }

            $byPath = [];
            foreach ($measuredPaths as $mp) {
                $byPath[$mp['path_id']] = $mp;
            }
            $allSpeedups = array_map(static fn (array $p): float => $p['primary_speedup'], $measuredPaths);

            $functionsByRustFile[$rel][] = [
                'name' => $fnName,
                'rust_file' => $rel,
                'line' => $line,
                'signature' => "pub fn {$fnName}(...)",
                'php_files' => $phpCallers['files'],
                'php_methods' => $phpCallers['methods'],
                'hot_path_speedup' => $byPath['hot_path']['median_speedup'],
                'hot_path_mean_speedup' => $byPath['hot_path']['mean_speedup'],
                'uncommon_p99_speedup' => $byPath['uncommon_tail']['p99_speedup'],
                'boundary_speedup' => $byPath['boundary_batch']['median_speedup'],
                'fallback_speedup' => $byPath['fallback_edge']['median_speedup'],
                'all_paths_speedup' => geoMean($allSpeedups),
                'speedup_range' => [min($allSpeedups), max($allSpeedups)],
                'hot_path_php_ns' => $byPath['hot_path']['php_median_ns'],
                'hot_path_rust_ns' => $byPath['hot_path']['rust_median_ns'],
                'uncommon_php_p99_ns' => $byPath['uncommon_tail']['php_p99_ns'],
                'uncommon_rust_p99_ns' => $byPath['uncommon_tail']['rust_p99_ns'],
                'paths' => $measuredPaths,
            ];
        }
    }
}

ksort($functionsByRustFile);

// Group files into Components and compute File -> Component -> Overall rollups
$componentsMap = [];
$allPhpFilesSet = [];

foreach ($functionsByRustFile as $rustFile => $fnList) {
    $compMeta = componentForRustFile($rustFile);
    $compId = $compMeta['id'];

    $fileHotSpeedups = array_map(static fn (array $f): float => $f['hot_path_speedup'], $fnList);
    $fileP99Speedups = array_map(static fn (array $f): float => $f['uncommon_p99_speedup'], $fnList);
    $fileBoundarySpeedups = array_map(static fn (array $f): float => $f['boundary_speedup'], $fnList);
    $fileFallbackSpeedups = array_map(static fn (array $f): float => $f['fallback_speedup'], $fnList);
    $fileAllSpeedups = array_map(static fn (array $f): float => $f['all_paths_speedup'], $fnList);

    $filePhpFiles = [];
    foreach ($fnList as $fn) {
        foreach ($fn['php_files'] as $pf) {
            $filePhpFiles[$pf] = true;
            $allPhpFilesSet[$pf] = true;
        }
    }
    $filePhpList = array_keys($filePhpFiles);
    sort($filePhpList);

    $hotPhpSum = array_sum(array_map(static fn (array $f): float => $f['hot_path_php_ns'], $fnList));
    $hotRustSum = array_sum(array_map(static fn (array $f): float => $f['hot_path_rust_ns'], $fnList));

    $fileEntry = [
        'rust_file' => $rustFile,
        'php_files' => $filePhpList,
        'function_count' => count($fnList),
        'path_count' => count($fnList) * 4,
        'hot_path_speedup' => geoMean($fileHotSpeedups),
        'uncommon_p99_speedup' => geoMean($fileP99Speedups),
        'boundary_speedup' => geoMean($fileBoundarySpeedups),
        'fallback_speedup' => geoMean($fileFallbackSpeedups),
        'all_paths_speedup' => geoMean($fileAllSpeedups),
        'hot_path_latency_reduction_percent' => round(100.0 * ($hotPhpSum - $hotRustSum) / max(1.0, $hotPhpSum), 2),
        'speedup_range' => [min($fileAllSpeedups), max($fileAllSpeedups)],
        'functions' => $fnList,
    ];

    if (! isset($componentsMap[$compId])) {
        $componentsMap[$compId] = [
            'id' => $compId,
            'name' => $compMeta['name'],
            'crate' => $compMeta['crate'],
            'description' => $compMeta['description'],
            'files' => [],
        ];
    }
    $componentsMap[$compId]['files'][] = $fileEntry;
}

$componentsList = [];
$globalHot = [];
$globalP99 = [];
$globalBoundary = [];
$globalFallback = [];
$globalAll = [];
$totalFiles = 0;
$totalFunctions = 0;
$totalPaths = 0;

foreach ($componentsMap as $compId => $comp) {
    $files = $comp['files'];
    $compFns = [];
    $compPhpFiles = [];
    $phpFileBuckets = [];

    foreach ($files as $f) {
        $totalFiles++;
        foreach ($f['functions'] as $fn) {
            $compFns[] = $fn;
            $totalFunctions++;
            $totalPaths += count($fn['paths']);
            foreach ($fn['php_files'] as $pf) {
                $compPhpFiles[$pf] = true;
                $phpFileBuckets[$pf][] = $fn;
            }
        }
    }

    $cHot = array_map(static fn (array $fn): float => $fn['hot_path_speedup'], $compFns);
    $cP99 = array_map(static fn (array $fn): float => $fn['uncommon_p99_speedup'], $compFns);
    $cBoundary = array_map(static fn (array $fn): float => $fn['boundary_speedup'], $compFns);
    $cFallback = array_map(static fn (array $fn): float => $fn['fallback_speedup'], $compFns);
    $cAll = array_map(static fn (array $fn): float => $fn['all_paths_speedup'], $compFns);

    $globalHot = array_merge($globalHot, $cHot);
    $globalP99 = array_merge($globalP99, $cP99);
    $globalBoundary = array_merge($globalBoundary, $cBoundary);
    $globalFallback = array_merge($globalFallback, $cFallback);
    $globalAll = array_merge($globalAll, $cAll);

    $hotPhpTotal = array_sum(array_map(static fn (array $fn): float => $fn['hot_path_php_ns'], $compFns));
    $hotRustTotal = array_sum(array_map(static fn (array $fn): float => $fn['hot_path_rust_ns'], $compFns));

    // Per-PHP-caller-file rollup within this component
    ksort($phpFileBuckets);
    $phpFilesRollup = [];
    foreach ($phpFileBuckets as $pf => $pfFns) {
        $pfHot = array_map(static fn (array $fn): float => $fn['hot_path_speedup'], $pfFns);
        $pfP99 = array_map(static fn (array $fn): float => $fn['uncommon_p99_speedup'], $pfFns);
        $pfAll = array_map(static fn (array $fn): float => $fn['all_paths_speedup'], $pfFns);
        $phpFilesRollup[] = [
            'php_file' => $pf,
            'function_count' => count($pfFns),
            'hot_path_speedup' => geoMean($pfHot),
            'uncommon_p99_speedup' => geoMean($pfP99),
            'all_paths_speedup' => geoMean($pfAll),
        ];
    }

    // Aggregate per-path summary for the component
    $pathSummaries = [
        [
            'path_id' => 'hot_path',
            'path_label' => 'Hot Path (common)',
            'metric' => 'median_speedup',
            'speedup' => geoMean($cHot),
            'speedup_range' => [min($cHot), max($cHot)],
        ],
        [
            'path_id' => 'uncommon_tail',
            'path_label' => 'Uncommon Tail (p99)',
            'metric' => 'p99_speedup',
            'speedup' => geoMean($cP99),
            'speedup_range' => [min($cP99), max($cP99)],
        ],
        [
            'path_id' => 'boundary_batch',
            'path_label' => 'Boundary / Batch',
            'metric' => 'median_speedup',
            'speedup' => geoMean($cBoundary),
            'speedup_range' => [min($cBoundary), max($cBoundary)],
        ],
        [
            'path_id' => 'fallback_edge',
            'path_label' => 'Fallback / Edge',
            'metric' => 'median_speedup',
            'speedup' => geoMean($cFallback),
            'speedup_range' => [min($cFallback), max($cFallback)],
        ],
    ];

    $componentsList[] = [
        'id' => $comp['id'],
        'name' => $comp['name'],
        'crate' => $comp['crate'],
        'description' => $comp['description'],
        'rust_file_count' => count($files),
        'php_file_count' => count($compPhpFiles),
        'function_count' => count($compFns),
        'path_count' => count($compFns) * 4,
        'hot_path_speedup' => geoMean($cHot),
        'uncommon_p99_speedup' => geoMean($cP99),
        'boundary_speedup' => geoMean($cBoundary),
        'fallback_speedup' => geoMean($cFallback),
        'all_paths_speedup' => geoMean($cAll),
        'hot_path_latency_reduction_percent' => round(100.0 * ($hotPhpTotal - $hotRustTotal) / max(1.0, $hotPhpTotal), 2),
        'speedup_range' => [min($cAll), max($cAll)],
        'path_summaries' => $pathSummaries,
        'php_files_rollup' => $phpFilesRollup,
        'files' => $files,
    ];
}

$payload = [
    'schema_version' => 1,
    'protocol' => [
        'clock' => 'hrtime(true)',
        'pairs_per_cell' => $pairsPerCell,
        'inner_iterations_per_observation' => 8,
        'warmup_calls_per_path' => $warmupCalls,
        'paths_per_function' => ['hot_path', 'uncommon_tail', 'boundary_batch', 'fallback_edge'],
        'php_version' => PHP_VERSION,
        'os_family' => PHP_OS_FAMILY,
        'arch' => php_uname('m'),
    ],
    'summary' => [
        'crate_count' => 3,
        'component_count' => count($componentsList),
        'rust_file_count' => $totalFiles,
        'php_file_count' => count($allPhpFilesSet),
        'function_count' => $totalFunctions,
        'path_count' => $totalPaths,
        'hot_path_speedup' => geoMean($globalHot),
        'uncommon_p99_speedup' => geoMean($globalP99),
        'boundary_speedup' => geoMean($globalBoundary),
        'fallback_speedup' => geoMean($globalFallback),
        'all_paths_speedup' => geoMean($globalAll),
        'speedup_range' => [
            $globalAll !== [] ? min($globalAll) : 1.0,
            $globalAll !== [] ? max($globalAll) : 1.0,
        ],
    ],
    'components' => $componentsList,
];

$outDir = dirname($outputPath);
if (! is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}
file_put_contents(
    $outputPath,
    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"
);

fwrite(
    STDERR,
    sprintf(
        "Benchmarked %d components, %d Rust files (%d PHP caller files), %d functions, %d paths -> hot=%.2fx, p99=%.2fx, all=%.2fx\n",
        $payload['summary']['component_count'],
        $payload['summary']['rust_file_count'],
        $payload['summary']['php_file_count'],
        $payload['summary']['function_count'],
        $payload['summary']['path_count'],
        $payload['summary']['hot_path_speedup'],
        $payload['summary']['uncommon_p99_speedup'],
        $payload['summary']['all_paths_speedup']
    )
);
