<?php
// Builds samples.txt: string literals from the PHP tests that mention the ported classes.
//   php build_samples.php <php-final checkout> > samples.txt
// Same escaping as the categorize corpus: backslash is \\ and a newline is \n.
declare(strict_types=1);

$ref = $argv[1];
$classes = '/NameCheckerService|NfoNameExtractor|FileNameExtractor|FilePrioritizer|DonorMatchSelector|ConstrainedPredbMatcher|PredbIdentityKey|TvNameChecker|MovieNameChecker|GameNameChecker|AppNameChecker|NameFixResult/';
$names = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ref.'/tests', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $path = $file->getPathname();
    if (! str_ends_with($path, '.php') || (! str_contains($path, '/tests/Unit/') && ! str_contains($path, '/tests/Feature/'))) {
        continue;
    }
    $source = (string) file_get_contents($path);
    if (! preg_match($classes, $source)) {
        continue;
    }
    foreach (token_get_all($source) as $token) {
        if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $value = eval('return '.$token[1].';');
        if (! is_string($value) || strlen($value) < 6 || strlen($value) > 300 || ! mb_check_encoding($value, 'UTF-8')) {
            continue;
        }
        if (! preg_match('/[._ -]/', $value) || preg_match('/^(App|Tests)\\\\|^(SELECT|INSERT|UPDATE|DELETE|CREATE)\b|::|->|\$[a-z]/i', $value)) {
            continue;
        }
        $names[$value] = true;
    }
}
$names = array_keys($names);
sort($names, SORT_STRING);
foreach ($names as $name) {
    echo str_replace(["\\", "\n"], ["\\\\", '\n'], (string) $name), "\n";
}
