<?php

declare(strict_types=1);

use App\Models\Release;
use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\Releases\ReleaseBrowseService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Tests\Unit\AdditionalProcessing\CreatesProcessingConfiguration;

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 8 || ! in_array($arguments[2], ['baseline', 'native'], true)
    || ! ctype_digit($arguments[5]) || (int) $arguments[5] < 1
) {
    throw new InvalidArgumentException('Expected repo, mode, fixtures, fixture, positive iterations, native library, and 7z.');
}
[$worker, $repo, $mode, $fixtureRoot, $fixtureName, $iterationArgument, $nativeLibrary, $sevenZip] = $arguments;
$iterations = (int) $iterationArgument;
require $repo.'/vendor/autoload.php';
require_once $repo.'/tests/Unit/AdditionalProcessing/CreatesProcessingConfiguration.php';
$container = new Application($repo);
$container->instance('config', new Repository(['yenc' => ['native_library' => $nativeLibrary]]));
Container::setInstance($container);
Facade::setFacadeApplication($container);
$factory = new class
{
    use CreatesProcessingConfiguration;

    /** @param array<string, mixed> $overrides */
    public function make(array $overrides): ProcessingConfiguration
    {
        return $this->makeConfig($overrides);
    }
};
$config = $factory->make($mode === 'baseline' ? ['sevenZipPath' => $sevenZip] : []);
$service = new ArchiveExtractionService($config);
$manifest = json_decode(file_get_contents($fixtureRoot.'/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
$fixture = $manifest[$fixtureName];
$head = file_get_contents($fixtureRoot.'/'.$fixture['head']);
$tail = $fixture['tail'] === null ? null : file_get_contents($fixtureRoot.'/'.$fixture['tail']);
$context = new ReleaseProcessingContext(new Release(['id' => 1, 'guid' => 'benchmark-fixture', 'size' => $fixture['archive_size']]));
$tmp = sys_get_temp_dir().'/7z-benchmark-'.bin2hex(random_bytes(8)).'/';
mkdir($tmp);
try {
    /** @param array<string, mixed>|null $result
     * @return array<string, mixed>|null
     */
    $normalize = static function (?array $result): ?array {
        if ($result === null) {
            return null;
        }
        $files = array_map(static function (array $f): array {
            $crc = isset($f['crc32']) ? str_pad(strtolower((string) $f['crc32']), 8, '0', STR_PAD_LEFT) : null;

            return ['name' => $f['name'], 'size' => $f['size'], 'date' => $f['date'], 'pass' => $f['pass'], 'compressed' => $f['compressed'], 'crc32' => $crc];
        }, $result['files']);
        $encrypted = $result['hasPassword'] || array_any($files, static fn (array $f): bool => $f['pass'] === 1);

        return ['files' => $files, 'encrypted' => $encrypted, 'success' => $result['success']];
    };
    $checks = [];
    $normal = null;
    $consistent = true;
    /** @param array<string, mixed>|null $result */
    $check = static function (?array $result, string $phase, int $index) use ($normalize, &$checks, &$normal, &$consistent): void {
        $value = $normalize($result);
        $valid = $value !== null && ($value['success'] ?? null) === true
            && ($result['listingOnly'] ?? null) === true
            && is_bool($result['hasPassword'] ?? null)
            && is_int($result['passwordStatus'] ?? null)
            && (! $result['hasPassword'] || $result['passwordStatus'] === ReleaseBrowseService::PASSWD_RAR);
        if ($phase === 'cold') {
            $normal = $value;
        }
        $matches = $normal === $value;
        $consistent = $consistent && $valid && $matches;
        $checks[] = ['phase' => $phase, 'index' => $index, 'valid' => $valid,
            'matches_first' => $matches,
            'signature' => hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)),
            'reason' => ! $valid ? 'Invalid success/password/listing-only contract' : (! $matches ? 'Result differs from first call' : null)];
    };
    $invoke = fn () => $service->listEncodedSevenZip($head, $tail, $context, $tmp);
    $begin = hrtime(true);
    $first = $invoke();
    $cold = hrtime(true) - $begin;
    $check($first, 'cold', 0);
    for ($i = 0; $i < 5; $i++) {
        $check($invoke(), 'warmup', $i);
    }
    $elapsed = [];
    $result = null;
    for ($i = 0; $i < $iterations; $i++) {
        $begin = hrtime(true);
        $result = $invoke();
        $elapsed[] = hrtime(true) - $begin;
        $check($result, 'measured', $i);
    }
    echo json_encode(['mode' => $mode, 'fixture' => $fixtureName, 'shape' => $fixture['tail'] === null ? 'full' : 'sparse', 'iterations' => $iterations,
        'cold_ns' => $cold, 'elapsed_ns' => $elapsed, 'result' => $normal, 'consistent' => $consistent, 'call_checks' => $checks],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
} finally {
    foreach (glob($tmp.'*') ?: [] as $f) {
        unlink($f);
    }
    rmdir($tmp);
}
