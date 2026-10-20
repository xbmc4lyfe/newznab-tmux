<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Initialize a new disposable integration database; never drops or replaces existing tables.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$url = getenv('FINGERPRINT_TEST_DATABASE_URL');
if ($url === false || $url === '') {
    throw new RuntimeException('Set FINGERPRINT_TEST_DATABASE_URL to a new disposable database.');
}
config(['database.default' => 'fingerprint_test', 'database.connections.fingerprint_test' => [
    'driver' => 'mariadb', 'url' => preg_replace('/^mysql:/', 'mariadb:', $url), 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '', 'strict' => true, 'engine' => 'InnoDB',
]]);
DB::purge('fingerprint_test');
foreach (['releases', 'release_nzb_contents', 'release_fingerprint_jobs'] as $table) {
    if (Schema::hasTable($table)) {
        throw new RuntimeException('Integration database already contains application tables; refusing to initialize.');
    }
}
Schema::create('releases', static function (Blueprint $table): void {
    $table->increments('id');
    $table->string('guid', 40)->unique();
    $table->unsignedTinyInteger('nzbstatus')->default(1);
});
foreach (['2026_10_12_120000_create_release_fingerprint_metadata_table.php',
    '2026_10_18_000000_add_run_token_to_release_fingerprint_metadata.php',
    '2026_10_16_010000_create_release_archive_naming_evidence_table.php',
    '2026_10_20_200000_create_database_fingerprint_storage.php'] as $file) {
    (require database_path('migrations/'.$file))->up();
}
echo "Disposable fingerprint integration schema initialized.\n";
