<?php

declare(strict_types=1);

/**
 * Host-only benchmark. Each scenario runs in a fresh process; fixture creation is untimed.
 * php scripts/benchmark-yenc.php [--native=/absolute/librapidyenc.so] [--baseline=/snapshot/directory]
 * A baseline directory contains unmodified YencService.php and NNTPService.php snapshots.
 * Optional --size=524288 restricts the run. Output is JSON Lines, with median/range and Linux peak RSS.
 */
use App\Services\Yenc\DecoderFactory;
use App\Services\YencService;
use Tests\Fixtures\YencArticles;
use Tests\Support\YencNntpHarness;

require dirname(__DIR__).'/vendor/autoload.php';

$options = getopt('', ['native:', 'baseline:', 'size:', 'samples:', 'worker:', 'data-path:', 'article-path:', 'path:']);
$samples = (int) ($options['samples'] ?? 11);
if ($samples < 3) {
    throw new InvalidArgumentException('Use at least three samples.');
}

if (isset($options['worker'])) {
    $mode = $options['worker'];
    if ($mode === 'baseline') {
        require $options['baseline'].'/YencService.php';
        require $options['baseline'].'/NNTPService.php';
        $service = new YencService;
    } else {
        $service = new YencService((new DecoderFactory)->make($mode, (string) ($options['native'] ?? '')));
    }
    $data = file_get_contents($options['data-path']);
    $article = file_get_contents($options['article-path']);
    $path = $options['path'];
    $times = [];
    $stream = null;
    $harness = null;
    if ($path === 'body') {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, preg_replace('/^\./m', '..', $article)."\r\n.\r\n");
        $harness = new YencNntpHarness($stream, $service);
    }
    try {
        for ($sample = -1; $sample < $samples; $sample++) {
            $input = $article;
            if ($stream !== null) {
                rewind($stream);
            }
            $start = hrtime(true);
            $result = match ($path) {
                'strict' => $service->decode($input),
                'body' => $harness->fetch(),
                default => $service->decodeIgnore($input),
            };
            $elapsed = (hrtime(true) - $start) / 1e6;
            if ($result !== $data) {
                echo json_encode(['status' => 'incorrect', 'pcre_error' => preg_last_error_msg()], JSON_THROW_ON_ERROR).PHP_EOL;
                exit(0);
            }
            if ($sample >= 0) {
                $times[] = $elapsed;
            }
            unset($input, $result);
        }
        sort($times);
        $rss = null;
        if (is_readable('/proc/self/status')) {
            preg_match('/^VmHWM:\s+(\d+) kB/m', file_get_contents('/proc/self/status'), $match);
            $rss = isset($match[1]) ? (int) $match[1] * 1024 : null;
        }
        echo json_encode([
            'status' => 'ok', 'median_ms' => $times[intdiv($samples, 2)],
            'min_ms' => $times[0], 'max_ms' => $times[$samples - 1],
            'peak_rss_bytes' => $rss, 'php_peak_bytes' => memory_get_peak_usage(true),
        ], JSON_THROW_ON_ERROR).PHP_EOL;
    } finally {
        if ($stream !== null) {
            fclose($stream);
        }
    }
    exit(0);
}

$sizes = isset($options['size']) ? [(int) $options['size']] : [4096, 524288, 1048576, 8388608];
if (min($sizes) < 1 || max($sizes) > 8388608) {
    throw new InvalidArgumentException('Benchmark sizes must be between 1 and 8388608 bytes.');
}
$modes = isset($options['baseline']) ? ['baseline', 'php'] : ['php'];
if (isset($options['native'])) {
    $modes[] = 'native';
}
$directory = sys_get_temp_dir().'/nntmux-yenc-bench-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
$dataPath = $directory.'/data';
$articlePath = $directory.'/article';
echo json_encode([
    'php' => PHP_VERSION, 'os' => php_uname(), 'samples' => $samples, 'warmups' => 1,
    'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'),
    'memory_limit' => ini_get('memory_limit'), 'ffi' => ini_get('ffi.enable'),
    'baseline_sha256' => isset($options['baseline']) ? hash_file('sha256', $options['baseline'].'/YencService.php') : null,
], JSON_THROW_ON_ERROR).PHP_EOL;
try {
    foreach ($sizes as $size) {
        foreach (['ordinary', 'heavy'] as $density) {
            $seed = $density === 'heavy' ? "\xd6\xe0\xe3\x13" : YencArticles::bytes();
            $data = substr(str_repeat($seed, (int) ceil($size / strlen($seed))), 0, $size);
            file_put_contents($dataPath, $data);
            file_put_contents($articlePath, YencArticles::article($data));
            unset($data);
            foreach ($modes as $mode) {
                foreach (['tolerant', 'strict', 'body'] as $path) {
                    $command = [PHP_BINARY, __FILE__, '--worker='.$mode, '--path='.$path,
                        '--samples='.$samples, '--data-path='.$dataPath, '--article-path='.$articlePath];
                    foreach (['native', 'baseline'] as $option) {
                        if (isset($options[$option])) {
                            $command[] = '--'.$option.'='.$options[$option];
                        }
                    }
                    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                    if (! is_resource($process)) {
                        throw new RuntimeException('Unable to start benchmark child.');
                    }
                    fclose($pipes[0]);
                    $output = stream_get_contents($pipes[1]);
                    $error = stream_get_contents($pipes[2]);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    $code = proc_close($process);
                    if ($code !== 0 || $error !== '') {
                        throw new RuntimeException("Benchmark child failed ({$code}): {$error}");
                    }
                    $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
                    if ($mode !== 'baseline' && $result['status'] !== 'ok') {
                        throw new RuntimeException("Incorrect {$mode} decoding for {$size} bytes ({$density}, {$path}).");
                    }
                    echo json_encode(['size' => $size, 'density' => $density, 'backend' => $mode, 'path' => $path] + $result, JSON_THROW_ON_ERROR).PHP_EOL;
                }
            }
        }
    }
} finally {
    foreach ([$dataPath, $articlePath] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($directory);
}
