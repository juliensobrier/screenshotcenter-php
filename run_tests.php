#!/usr/bin/env php
<?php
/**
 * Standalone test runner — works with any PHP 7.2+ install.
 * Use this when PHPUnit is unavailable (missing dom extension etc.).
 *
 * Usage:  php run_tests.php
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use ScreenshotCenter\Client;
use ScreenshotCenter\HttpResponse;
use ScreenshotCenter\Errors\ApiError;
use ScreenshotCenter\Errors\TimeoutError;
use ScreenshotCenter\Errors\ScreenshotFailedError;

// ── Tiny test framework ───────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "\033[32m  ✓\033[0m {$name}\n";
        $passed++;
    } catch (Throwable $e) {
        echo "\033[31m  ✗\033[0m {$name}\n    → {$e->getMessage()}\n";
        $failed++;
    }
}

function assertSame2($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \AssertionError($msg ?: "Expected " . var_export($expected, true) . ", got " . var_export($actual, true));
    }
}

function assertContains2(string $needle, string $haystack, string $msg = ''): void
{
    if (strpos($haystack, $needle) === false) {
        throw new \AssertionError($msg ?: "Expected '{$haystack}' to contain '{$needle}'");
    }
}

function assertIsArray2($value): void
{
    if (!is_array($value)) {
        throw new \AssertionError("Expected array, got " . gettype($value));
    }
}

function assertInstanceOf2(string $class, $obj): void
{
    if (!($obj instanceof $class)) {
        throw new \AssertionError("Expected instance of {$class}, got " . get_class($obj));
    }
}

function assertFileExists2(string $path): void
{
    if (!file_exists($path)) {
        throw new \AssertionError("File not found: {$path}");
    }
}

function expectThrows(string $class, callable $fn): object
{
    try {
        $fn();
        throw new \AssertionError("Expected {$class} to be thrown, but nothing was thrown");
    } catch (\AssertionError $e) {
        throw $e;
    } catch (\Throwable $e) {
        if (!($e instanceof $class)) {
            throw new \AssertionError("Expected {$class}, got " . get_class($e) . ": " . $e->getMessage());
        }
        return $e;
    }
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

$SCREENSHOT = [
    'id' => 1001, 'status' => 'finished', 'url' => 'https://example.com',
    'final_url' => 'https://example.com/', 'error' => null, 'cost' => 1,
    'tag' => [], 'created_at' => '2026-01-01T00:00:00Z',
    'finished_at' => '2026-01-01T00:00:05Z', 'country' => 'us',
    'has_html' => false, 'has_pdf' => false, 'has_video' => false, 'shots' => 1,
];

$BATCH   = ['id' => 2001, 'status' => 'finished', 'count' => 3, 'processed' => 3, 'failed' => 0];
$ACCOUNT = ['balance' => 500];

function jsonResp($data, int $status = 200): HttpResponse
{
    return new HttpResponse($status, json_encode(['success' => true, 'data' => $data]), 'application/json');
}

function errorResp(string $msg, int $status): HttpResponse
{
    return new HttpResponse($status, json_encode(['success' => false, 'error' => $msg]), 'application/json');
}

function binaryResp(string $content): HttpResponse
{
    return new HttpResponse(200, $content, 'image/png');
}

function makeClient(array $responses): Client
{
    $transport = function () use (&$responses): HttpResponse {
        return array_shift($responses);
    };
    return new Client('test-key', 'https://api.screenshotcenter.com/api/v1', 30, $transport);
}

function captureClient(array &$calls, array $responses): Client
{
    $transport = function ($method, $url, $body, $ct) use (&$calls, &$responses): HttpResponse {
        $calls[] = compact('method', 'url', 'body', 'ct');
        return array_shift($responses);
    };
    return new Client('test-key', 'https://api.screenshotcenter.com/api/v1', 30, $transport);
}

// ── Tests ─────────────────────────────────────────────────────────────────────

echo "\nScreenshotCenter PHP SDK — unit tests\n\n";

// Constructor
test('throws on empty apiKey', function () {
    expectThrows(\InvalidArgumentException::class, function () { new Client(''); });
});

test('uses default base URL', function () {
    $c   = new Client('key');
    $url = Closure::bind(function () { return $this->baseUrl; }, $c, $c)();
    assertContains2('api.screenshotcenter.com', $url);
});

test('accepts custom base URL', function () {
    $c   = new Client('key', 'http://localhost:3000/api/v1');
    $url = Closure::bind(function () { return $this->baseUrl; }, $c, $c)();
    assertContains2('localhost', $url);
});

test('strips trailing slash from base URL', function () {
    $c   = new Client('key', 'http://example.com/api/v1/');
    $url = Closure::bind(function () { return $this->baseUrl; }, $c, $c)();
    assertSame2(false, substr($url, -1) === '/', 'URL should not end with /');
});

test('namespaces are attached', function () {
    $client = new Client('key');
    assertInstanceOf2(\ScreenshotCenter\ScreenshotNamespace::class, $client->screenshot);
    assertInstanceOf2(\ScreenshotCenter\BatchNamespace::class, $client->batch);
    assertInstanceOf2(\ScreenshotCenter\AccountNamespace::class, $client->account);
});

// screenshot.create
test('create returns screenshot array', function () use ($SCREENSHOT) {
    $client = makeClient([jsonResp($SCREENSHOT)]);
    $result = $client->screenshot->create('https://example.com');
    assertSame2(1001, $result['id']);
    assertSame2('finished', $result['status']);
});

test('create sends url and key', function () use ($SCREENSHOT) {
    $calls  = [];
    $client = captureClient($calls, [jsonResp($SCREENSHOT)]);
    $client->screenshot->create('https://example.com');
    assertContains2('url=https', $calls[0]['url']);
    assertContains2('key=test-key', $calls[0]['url']);
});

test('create passes optional params', function () use ($SCREENSHOT) {
    $calls  = [];
    $client = captureClient($calls, [jsonResp($SCREENSHOT)]);
    $client->screenshot->create('https://example.com', ['country' => 'fr', 'shots' => 3]);
    assertContains2('country=fr', $calls[0]['url']);
    assertContains2('shots=3', $calls[0]['url']);
});

test('create passes unknown future params', function () use ($SCREENSHOT) {
    $calls  = [];
    $client = captureClient($calls, [jsonResp($SCREENSHOT)]);
    $client->screenshot->create('https://example.com', ['future_param' => 'xyz']);
    assertContains2('future_param=xyz', $calls[0]['url']);
});

test('create raises on empty url', function () {
    expectThrows(\InvalidArgumentException::class, function () { (new Client('key'))->screenshot->create(''); });
});

test('create raises ApiError on 401', function () {
    $client = makeClient([errorResp('Unauthorized', 401)]);
    $e = expectThrows(ApiError::class, function () use ($client) {
        $client->screenshot->create('https://example.com');
    });
    assertSame2(401, $e->status);
});

test('create ApiError has code and fields', function () {
    $body = json_encode(['success' => false, 'error' => 'Validation failed', 'code' => 'VALIDATION_ERROR', 'fields' => ['url' => ['Invalid URL']]]);
    $resp = new HttpResponse(422, $body, 'application/json');
    $client = new Client('key', 'https://api.screenshotcenter.com/api/v1', 30, function () use ($resp) { return $resp; });
    $e = expectThrows(ApiError::class, function () use ($client) {
        $client->screenshot->create('not-a-url');
    });
    assertSame2(422, $e->status);
    assertSame2('VALIDATION_ERROR', $e->code);
});

// screenshot.info
test('info returns screenshot', function () use ($SCREENSHOT) {
    $client = makeClient([jsonResp($SCREENSHOT)]);
    $result = $client->screenshot->info(1001);
    assertSame2(1001, $result['id']);
});

test('info sends id param', function () use ($SCREENSHOT) {
    $calls  = [];
    $client = captureClient($calls, [jsonResp($SCREENSHOT)]);
    $client->screenshot->info(1001);
    assertContains2('id=1001', $calls[0]['url']);
});

test('info raises ApiError on 404', function () {
    $client = makeClient([errorResp('Not found', 404)]);
    $e = expectThrows(ApiError::class, function () use ($client) { $client->screenshot->info(999); });
    assertSame2(404, $e->status);
});

// screenshot.list
test('list returns array', function () use ($SCREENSHOT) {
    $client = makeClient([jsonResp([$SCREENSHOT])]);
    $result = $client->screenshot->list();
    assertIsArray2($result);
    assertSame2(1, count($result));
});

test('list passes params', function () {
    $calls  = [];
    $client = captureClient($calls, [jsonResp([])]);
    $client->screenshot->list(['limit' => 5, 'offset' => 10]);
    assertContains2('limit=5', $calls[0]['url']);
    assertContains2('offset=10', $calls[0]['url']);
});

// screenshot.search
test('search returns array', function () use ($SCREENSHOT) {
    $client = makeClient([jsonResp([$SCREENSHOT])]);
    $result = $client->screenshot->search('example.com');
    assertIsArray2($result);
});

test('search sends url param', function () {
    $calls  = [];
    $client = captureClient($calls, [jsonResp([])]);
    $client->screenshot->search('example.com');
    assertContains2('url=example.com', $calls[0]['url']);
});

test('search raises on empty url', function () {
    expectThrows(\InvalidArgumentException::class, function () { (new Client('key'))->screenshot->search(''); });
});

// screenshot.thumbnail
test('thumbnail returns bytes', function () {
    $client = makeClient([binaryResp('PNG-DATA')]);
    $result = $client->screenshot->thumbnail(1001);
    assertSame2('PNG-DATA', $result);
});

test('thumbnail passes options', function () {
    $calls  = [];
    $client = captureClient($calls, [binaryResp('x')]);
    $client->screenshot->thumbnail(1001, ['width' => 400, 'shot' => 2]);
    assertContains2('width=400', $calls[0]['url']);
    assertContains2('shot=2', $calls[0]['url']);
});

// save helpers
test('saveImage writes file to disk', function () {
    $tmp    = tempnam(sys_get_temp_dir(), 'sc_') . '.png';
    $client = makeClient([binaryResp('PNG-CONTENT')]);
    $client->screenshot->saveImage(1001, $tmp);
    assertFileExists2($tmp);
    assertSame2('PNG-CONTENT', file_get_contents($tmp));
    unlink($tmp);
});

test('saveImage creates parent directories', function () {
    $dir    = sys_get_temp_dir() . '/sc_test_' . uniqid();
    $path   = "{$dir}/a/b/shot.png";
    $client = makeClient([binaryResp('PNG')]);
    $client->screenshot->saveImage(1001, $path);
    assertFileExists2($path);
    unlink($path); rmdir("{$dir}/a/b"); rmdir("{$dir}/a"); rmdir($dir);
});

test('savePdf writes file', function () {
    $tmp    = tempnam(sys_get_temp_dir(), 'sc_') . '.pdf';
    $client = makeClient([binaryResp('%PDF-1.4')]);
    $client->screenshot->savePdf(1001, $tmp);
    assertSame2('%PDF-1.4', file_get_contents($tmp));
    unlink($tmp);
});

test('saveHtml writes file', function () {
    $tmp    = tempnam(sys_get_temp_dir(), 'sc_') . '.html';
    $client = makeClient([binaryResp('<html></html>')]);
    $client->screenshot->saveHtml(1001, $tmp);
    assertSame2('<html></html>', file_get_contents($tmp));
    unlink($tmp);
});

// waitFor
test('waitFor resolves when finished', function () use ($SCREENSHOT) {
    $done   = array_merge($SCREENSHOT, ['status' => 'finished']);
    $client = makeClient([jsonResp($done)]);
    $result = $client->waitFor(1001);
    assertSame2('finished', $result['status']);
});

test('waitFor polls until finished', function () use ($SCREENSHOT) {
    $proc   = array_merge($SCREENSHOT, ['status' => 'processing']);
    $done   = array_merge($SCREENSHOT, ['status' => 'finished']);
    $client = makeClient([jsonResp($proc), jsonResp($proc), jsonResp($done)]);
    $result = $client->waitFor(1001, 0.001);
    assertSame2('finished', $result['status']);
});

test('waitFor raises ScreenshotFailedError on error status', function () use ($SCREENSHOT) {
    $err    = array_merge($SCREENSHOT, ['status' => 'error', 'error' => 'DNS failure']);
    $client = makeClient([jsonResp($err)]);
    $e = expectThrows(ScreenshotFailedError::class, function () use ($client) { $client->waitFor(1001); });
    assertSame2(1001, $e->screenshotId);
    assertSame2('DNS failure', $e->error);
});

test('waitFor raises TimeoutError', function () use ($SCREENSHOT) {
    $proc   = array_merge($SCREENSHOT, ['status' => 'processing']);
    $client = makeClient(array_fill(0, 10, jsonResp($proc)));
    expectThrows(TimeoutError::class, function () use ($client) { $client->waitFor(1001, 0.001, 0.001); });
});

// batch.create
test('batch create from array', function () use ($BATCH) {
    $client = makeClient([jsonResp($BATCH)]);
    $result = $client->batch->create(['https://example.com', 'https://example.org'], 'us');
    assertSame2(2001, $result['id']);
});

test('batch create from string', function () use ($BATCH) {
    $client = makeClient([jsonResp($BATCH)]);
    $result = $client->batch->create("https://example.com\nhttps://example.org", 'us');
    assertSame2(2001, $result['id']);
});

test('batch create uses POST method', function () use ($BATCH) {
    $calls  = [];
    $client = captureClient($calls, [jsonResp($BATCH)]);
    $client->batch->create(['https://example.com'], 'us');
    assertSame2('POST', $calls[0]['method']);
});

test('batch create raises on empty country', function () {
    expectThrows(\InvalidArgumentException::class, function () {
        (new Client('key'))->batch->create(['https://example.com'], '');
    });
});

// batch.waitFor
test('batch waitFor resolves on finished', function () use ($BATCH) {
    $client = makeClient([jsonResp($BATCH)]);
    $result = $client->batch->waitFor(2001);
    assertSame2('finished', $result['status']);
});

test('batch waitFor resolves on error status', function () use ($BATCH) {
    $err    = array_merge($BATCH, ['status' => 'error']);
    $client = makeClient([jsonResp($err)]);
    $result = $client->batch->waitFor(2001);
    assertSame2('error', $result['status']);
});

test('batch waitFor raises TimeoutError', function () use ($BATCH) {
    $proc   = array_merge($BATCH, ['status' => 'processing']);
    $client = makeClient(array_fill(0, 10, jsonResp($proc)));
    expectThrows(TimeoutError::class, function () use ($client) { $client->batch->waitFor(2001, 0.001, 0.001); });
});

// account.info
test('account info returns balance', function () use ($ACCOUNT) {
    $client = makeClient([jsonResp($ACCOUNT)]);
    $result = $client->account->info();
    assertSame2(500, $result['balance']);
});

test('account info sends API key', function () use ($ACCOUNT) {
    $calls  = [];
    $client = captureClient($calls, [jsonResp($ACCOUNT)]);
    $client->account->info();
    assertContains2('key=test-key', $calls[0]['url']);
});

// Error classes
test('ApiError has correct properties', function () {
    $e = new ApiError('Bad request', 400, 'INVALID_PARAMS', ['url' => ['required']]);
    assertSame2(400, $e->status);
    assertSame2('INVALID_PARAMS', $e->code);
    assertSame2('Bad request', $e->getMessage());
    assertSame2(['url' => ['required']], $e->fields);
});

test('TimeoutError has correct properties', function () {
    $e = new TimeoutError(1001, 30000);
    assertSame2(1001, $e->screenshotId);
    assertSame2(30000, $e->timeoutMs);
    assertContains2('1001', $e->getMessage());
});

test('ScreenshotFailedError has correct properties', function () {
    $e = new ScreenshotFailedError(1001, 'DNS failure');
    assertSame2(1001, $e->screenshotId);
    assertSame2('DNS failure', $e->error);
    assertContains2('DNS failure', $e->getMessage());
});

// ── Summary ───────────────────────────────────────────────────────────────────

echo "\n";
$total = $passed + $failed;
if ($failed === 0) {
    echo "\033[32m✓ {$passed}/{$total} tests passed\033[0m\n\n";
} else {
    echo "\033[31m✗ {$failed}/{$total} tests failed\033[0m\n\n";
    exit(1);
}
