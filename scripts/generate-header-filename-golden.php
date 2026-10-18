<?php

declare(strict_types=1);

/**
 * Regenerates docker/app/nzb-yenc-ffi/fixtures/header-filename-identity-v1.json: PHP's own
 * verdicts over the deterministic synthetic corpus, consumed by the Rust unit tests so they can
 * check byte-exact parity without PHP. tests/Unit/HeaderFilenameIdentityNativeTest.php fails
 * when this file no longer matches the live PHP rule.
 *
 *   php scripts/generate-header-filename-golden.php > docker/app/nzb-yenc-ffi/fixtures/header-filename-identity-v1.json
 */
use Tests\Support\HeaderFilenameIdentityCorpus;
use Tests\Unit\HeaderFilenameIdentityTest;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__).'/vendor/autoload.php';

const HEADER_FILENAME_GOLDEN_GENERATED = 1500;
const HEADER_FILENAME_GOLDEN_SEED = 20261018;

$cases = [];
foreach (HeaderFilenameIdentityCorpus::pairs(HEADER_FILENAME_GOLDEN_GENERATED, HEADER_FILENAME_GOLDEN_SEED) as [$subject, $actual]) {
    $cases[] = [base64_encode($subject), base64_encode($actual), HeaderFilenameIdentityCorpus::php($subject, $actual)];
}
echo json_encode(['format' => 'header-filename-identity-v1', 'generated' => HEADER_FILENAME_GOLDEN_GENERATED,
    'unit_cases' => count(HeaderFilenameIdentityTest::subjects()),
    'seed' => HEADER_FILENAME_GOLDEN_SEED, 'cases' => $cases], JSON_THROW_ON_ERROR)."\n";
