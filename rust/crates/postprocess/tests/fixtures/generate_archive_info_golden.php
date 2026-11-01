<?php
// Regenerates archive_info_golden.json from the real rarinfo library, inside the Laravel kernel so
// PHP warnings become exceptions as they did in production. After rust/porting/php-ref-setup.sh:
//   php generate_archive_info_golden.php <php-final checkout> <output directory>
// Runs over the library's own fixture archives, whole and cut short, and copies the ones the test
// replays into <output directory>/archives.
declare(strict_types=1);

$ref = $argv[1];
$out = $argv[2];
require $ref.'/vendor/autoload.php';
$app = require $ref.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use dariusiii\rarinfo\ArchiveInfo;
use dariusiii\rarinfo\Par2Info;

$base = $ref.'/vendor/dariusiii/rarinfo/tests/fixtures';
$paths = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $entry) {
    if ($entry->isFile() && $entry->getExtension() !== 'php' && $entry->getSize() > 0 && $entry->getSize() <= 262144) {
        $paths[] = $entry->getPathname();
    }
}
sort($paths);

$typeNames = [0 => 'None', ArchiveInfo::TYPE_RAR => 'Rar', ArchiveInfo::TYPE_ZIP => 'Zip', ArchiveInfo::TYPE_SRR => 'Srr', ArchiveInfo::TYPE_SFV => 'Sfv', ArchiveInfo::TYPE_PAR2 => 'Par2', ArchiveInfo::TYPE_SZIP => 'Szip'];

$row = static function (array $file): array {
    if (isset($file['error'])) {
        return ['error' => $file['error'], 'source' => $file['source'] ?? ''];
    }
    if (! isset($file['name']) && array_is_list($file)) {
        return ['nested' => array_map($GLOBALS['row'], $file)];
    }
    if (! isset($file['name'])) {
        return ['nested' => []];
    }

    return [
        'name' => base64_encode($file['name']),
        'size' => (int) ($file['size'] ?? 0),
        'date' => (int) ($file['date'] ?? 0),
        'pass' => (int) ($file['pass'] ?? 0),
        'compressed' => isset($file['compressed']) && $file['compressed'] !== '' ? (int) $file['compressed'] : null,
        'next_offset' => $file['next_offset'] ?? null,
        'is_dir' => ! empty($file['is_dir']),
        'range' => $file['range'] ?? null,
        'crc32' => $file['crc32'] ?? null,
        'split' => ! empty($file['split']),
        'split_after' => ! empty($file['split_after']),
        'source' => $file['source'] ?? '',
        'hash' => $file['hash'] ?? null,
        'hash_16k' => $file['hash_16K'] ?? null,
        'blocks' => $file['blocks'] ?? null,
        'checksum' => $file['checksum'] ?? null,
    ];
};
$GLOBALS['row'] = $row;

$describe = static function (string $data) use ($typeNames, $row): array {
    $archive = new ArchiveInfo;
    try {
        $opened = $archive->setData($data, true);
    } catch (Throwable $e) {
        return ['open_throws' => true];
    }
    $result = ['kind' => $typeNames[$archive->type], 'error' => (string) ($opened ? $archive->error : ($archive->error ?? '')), 'encrypted' => ! empty($archive->isEncrypted)];
    if ($archive->type === 0) {
        return $result;
    }
    try {
        $summary = $archive->getSummary($archive->type !== ArchiveInfo::TYPE_SZIP);
        $result['summary_throws'] = false;
        $result['is_volume'] = ! empty($summary['is_volume']);
        $result['enc_header'] = ! empty($summary['enc_header']);
    } catch (Throwable) {
        $result['summary_throws'] = true;

        return $result;
    }
    try {
        $list = $archive->getArchiveFileList($archive->type !== ArchiveInfo::TYPE_SZIP);
        $result['list'] = is_array($list) ? array_map($row, $list) : null;
        foreach ((is_array($list) ? $list : []) as $file) {
            if (isset($file['name'], $file['range']) && ($file['source'] ?? '') === 'main' && empty($file['is_dir']) && $archive->type !== ArchiveInfo::TYPE_SZIP) {
                try {
                    $bytes = $archive->getFileData($file['name']);
                    $result['data'][] = ['name' => base64_encode($file['name']), 'md5' => is_string($bytes) ? md5($bytes) : null];
                } catch (Throwable) {
                    $result['data'][] = ['name' => base64_encode($file['name']), 'md5' => null];
                }
            }
        }
    } catch (Throwable) {
        $result['list_throws'] = true;
    }
    if ($archive->type === ArchiveInfo::TYPE_RAR) {
        $reader = $archive->getReader();
        $blocks = $reader->getBlocks(false);
        $last = is_array($blocks) && $blocks !== [] ? end($blocks) : null;
        $result['terminal'] = is_array($last) && in_array($last['head_type'] ?? null, [0x7b, 0x05], true)
            && ($last['more_volumes'] ?? true) === false && ($last['next_offset'] ?? null) === strlen($data);
    }

    return $result;
};

@mkdir($out.'/archives', 0777, true);
$cases = [];
foreach ($paths as $path) {
    $relative = substr($path, strlen($base) + 1);
    $name = str_replace('/', '__', $relative);
    $data = (string) file_get_contents($path);
    copy($path, $out.'/archives/'.$name);
    $cuts = array_unique([strlen($data), (int) (strlen($data) * 0.75), (int) (strlen($data) / 2), min(strlen($data), 4096), min(strlen($data), 200), min(strlen($data), 40)]);
    foreach ($cuts as $cut) {
        foreach ([0, 17] as $skip) {
            if ($cut - $skip <= 0) {
                continue;
            }
            $cases[] = ['file' => $name, 'skip' => $skip, 'cut' => $cut, ...$describe(substr($data, $skip, $cut - $skip))];
        }
    }
}

// Par2Info and Par2FileList used directly.
$par2 = [];
foreach ($paths as $path) {
    if (! str_contains($path, '/par2/')) {
        continue;
    }
    $data = (string) file_get_contents($path);
    $reader = new Par2Info;
    $reader->setData($data);
    $list = new App\Services\Par2FileList;
    $par2[] = [
        'file' => str_replace('/', '__', substr($path, strlen($base) + 1)),
        'error' => (string) $reader->error,
        'unresolved' => $list->hasUnresolvedDescriptors($reader),
        'files' => array_values(array_map($row, $list->getFiles($reader))),
    ];
}

file_put_contents($out.'/archive_info_golden.json', json_encode(['cases' => $cases, 'par2' => $par2], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
