<?php
// Regenerates golden.jsonl from PHP's preg_match, over the seeded collection regexes and the
// example subjects in their descriptions, plus subjects that aren't valid UTF-8.
// Needs the test MariaDB with the PHP reference's nntmux database: rust/porting/php-ref-setup.sh
// Usage: php generate.php > golden.jsonl
$sql = 'SELECT HEX(regex), HEX(description) FROM collection_regexes ORDER BY id';
$rows = shell_exec('docker exec nntmux-rust-test-mariadb-1 mariadb -uroot -proot -N -B nntmux -e '.escapeshellarg($sql));
$extra = ["caf\xe9 [01/10] \"caf\xe9.rar\" yEnc", "\xff\xfe (1/2) yEnc", '[12345]-[FULL]-[#a.b.teevee]-[ Some.Show.S01E01-GRP ]-[01/10] - "some.nfo" yEnc'];
foreach (explode("\n", trim($rows)) as $row) {
    [$regex, $description] = array_map('hex2bin', explode("\t", $row));
    $subjects = array_merge(array_map('trim', explode(':::', $description)), $extra);
    foreach ($subjects as $subject) {
        $hit = @preg_match($regex, $subject, $groups);
        $named = null;
        if ($hit === 1) {
            ksort($groups);
            $named = [];
            foreach ($groups as $key => $value) {
                if (! is_int($key)) {
                    $named[] = [$key, bin2hex($value)];
                }
            }
        }
        // preg_match reports unmatched trailing groups as absent and inner ones as ''.
        echo json_encode(['regex' => bin2hex($regex), 'subject' => bin2hex($subject), 'whole' => $hit === 1 ? bin2hex($groups[0]) : null, 'named' => $named]), "\n";
    }
}
