<?php

/**
 * Emit one JSON line per class member (method, constant, enum case) found in the
 * given PHP files, plus one "file" line per file with the identifiers used outside
 * any member. Used by rewrite_metrics.py; no Laravel boot, no Composer needed.
 *
 * Usage: php php_members.php <pipeline_syms.txt> <live_ffi_syms.txt> <file>...
 */
$pipelineSyms = array_flip(array_filter(array_map('trim', file($argv[1]))));
$liveSyms = array_flip(array_filter(array_map('trim', file($argv[2]))));
$files = array_slice($argv, 3);

const WTAG = '/(^|[a-z_])[wW]\d{2,3}([A-Z_]|$)|^W\d{2,3}_|[wW]ave\d/';

function words(string $s): array
{
    preg_match_all('/[A-Za-z_][A-Za-z0-9_]{2,}/', $s, $m);

    return $m[0];
}

function lastLine(array $t, int $j): int
{
    for ($k = $j; $k >= 0; $k--) {
        if (is_array($t[$k])) {
            return $t[$k][2] + substr_count($t[$k][1], "\n");
        }
    }

    return 0;
}

foreach ($files as $file) {
    $src = @file_get_contents($file);
    if ($src === false) {
        continue;
    }
    try {
        $t = token_get_all($src, TOKEN_PARSE);
    } catch (Throwable $e) {
        fwrite(STDERR, "parse error: $file: {$e->getMessage()}\n");
        echo json_encode(['type' => 'file', 'file' => $file, 'lines' => substr_count($src, "\n") + 1, 'refs' => array_values(array_unique(words($src))), 'parse_error' => true]), "\n";

        continue;
    }
    $n = count($t);
    $depth = 0;
    $classDepth = null;
    $outside = [];
    $fileUsesPipeline = str_contains($src, 'RustPipelineLibrary');

    for ($i = 0; $i < $n; $i++) {
        $tk = $t[$i];
        if (is_string($tk)) {
            if ($tk === '{') {
                $depth++;
            } elseif ($tk === '}') {
                $depth--;
                if ($classDepth !== null && $depth < $classDepth) {
                    $classDepth = null;
                }
            }

            continue;
        }
        [$id, $text, $line] = $tk;
        if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;

            continue;
        }
        if (in_array($id, [T_CLASS, T_TRAIT, T_ENUM, T_INTERFACE], true) && $classDepth === null) {
            // Skip `Foo::class` and anonymous `new class` heads that have no body here.
            $prev = $i > 0 ? $t[$i - 1] : null;
            if (is_array($prev) && $prev[0] === T_DOUBLE_COLON) {
                continue;
            }
            for ($j = $i + 1; $j < $n; $j++) {
                if ($t[$j] === '{') {
                    $depth++;
                    $classDepth = $depth;
                    $i = $j;
                    break;
                }
                if ($t[$j] === ';' || $t[$j] === '(') {
                    break;
                }
            }

            continue;
        }
        if ($classDepth !== null && $depth === $classDepth && in_array($id, [T_FUNCTION, T_CONST, T_CASE], true)) {
            $name = '?';
            for ($j = $i + 1; $j < $n; $j++) {
                if ($id === T_CONST) {
                    if ($t[$j] === '=') {
                        break;
                    }
                    if (is_array($t[$j]) && $t[$j][0] === T_STRING) {
                        $name = $t[$j][1];
                    }
                } elseif (is_array($t[$j]) && $t[$j][0] === T_STRING) {
                    $name = $t[$j][1];
                    break;
                }
            }
            $start = $line;
            $wComment = false;
            for ($k = $i - 1; $k >= 0; $k--) {
                $p = $t[$k];
                if (is_array($p) && in_array($p[0], [T_WHITESPACE, T_PUBLIC, T_PRIVATE, T_PROTECTED, T_STATIC, T_FINAL, T_ABSTRACT, T_READONLY, T_DOC_COMMENT, T_COMMENT, T_ATTRIBUTE], true)) {
                    if ($p[0] !== T_WHITESPACE) {
                        $start = $p[2];
                    }
                    if (($p[0] === T_COMMENT || $p[0] === T_DOC_COMMENT) && preg_match('/\bW(ave)? ?\d{2,3}(\.\d+)?\b/', $p[1])) {
                        $wComment = true;
                    }
                } else {
                    break;
                }
            }
            $d = 0;
            $end = $line;
            $refs = [];
            for ($j = $i + 1; $j < $n; $j++) {
                $q = $t[$j];
                if (is_array($q)) {
                    if ($q[0] === T_CURLY_OPEN || $q[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                        $d++;
                    } elseif (in_array($q[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                        $parts = explode('\\', $q[1]);
                        $refs[] = end($parts);
                    } elseif (in_array($q[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                        foreach (words($q[1]) as $w) {
                            $refs[] = $w;
                        }
                    }

                    continue;
                }
                if ($q === '{') {
                    $d++;
                } elseif ($q === '}') {
                    $d--;
                    if ($d === 0 && $id === T_FUNCTION) {
                        $end = lastLine($t, $j);
                        break;
                    }
                } elseif ($q === ';' && $d === 0) {
                    $end = lastLine($t, $j);
                    break;
                }
            }
            $refs = array_values(array_unique($refs));
            $usesPipeline = false;
            $usesLive = false;
            foreach ($refs as $r) {
                if (isset($pipelineSyms[$r]) || $r === 'RustPipelineLibrary') {
                    $usesPipeline = true;
                }
                if (isset($liveSyms[$r])) {
                    $usesLive = true;
                }
            }
            echo json_encode([
                'type' => 'member',
                'file' => $file,
                'kind' => $id === T_FUNCTION ? 'fn' : ($id === T_CONST ? 'const' : 'case'),
                'name' => $name,
                'start' => $start,
                'end' => $end,
                'wtag' => $wComment || preg_match(WTAG, $name) === 1,
                'pipeline' => $usesPipeline,
                'live_ffi' => $usesLive,
                'ffi_name' => $fileUsesPipeline && preg_match('/ffi/i', $name) === 1,
                'refs' => $refs,
            ]), "\n";
            $i = $j;

            continue;
        }
        if (in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            $parts = explode('\\', $text);
            $outside[] = end($parts);
        } elseif (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            foreach (words($text) as $w) {
                $outside[] = $w;
            }
        }
    }
    echo json_encode(['type' => 'file', 'file' => $file, 'lines' => substr_count($src, "\n") + (str_ends_with($src, "\n") ? 0 : 1), 'refs' => array_values(array_unique($outside))]), "\n";
}
