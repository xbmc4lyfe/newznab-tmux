<?php

// PHP's built-in server answers HTTP QUERY with 501, so record.py sends QUERY cases through the
// HTTP kernel directly. Usage: php record_request.php <php-final checkout>, a case on stdin.

$base = $argv[1];
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$case = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$server = ['HTTP_HOST' => '127.0.0.1:8765', 'SERVER_NAME' => '127.0.0.1', 'SERVER_PORT' => 8765, 'REMOTE_ADDR' => '127.0.0.1'];
foreach (['Accept' => '*/*', 'User-Agent' => 'golden'] + ($case['headers'] ?? []) as $name => $value) {
    $key = strtoupper(str_replace('-', '_', $name));
    $server[$key === 'CONTENT_TYPE' ? $key : 'HTTP_'.$key] = $value;
}

$request = Illuminate\Http\Request::create($case['path'], $case['method'], [], [], [], $server, $case['body'] ?? null);
$response = $kernel->handle($request);
$headers = [];
foreach ($response->headers->allPreserveCase() as $name => $values) {
    foreach ($values as $value) {
        $headers[] = [strtolower($name), $value];
    }
}
echo json_encode(['status' => $response->getStatusCode(), 'headers' => $headers, 'body' => $response->getContent()], JSON_THROW_ON_ERROR);
