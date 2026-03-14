<?php

declare(strict_types=1);

namespace ScreenshotCenter\Tests;

use PHPUnit\Framework\TestCase;
use ScreenshotCenter\Client;
use ScreenshotCenter\HttpResponse;
use ScreenshotCenter\Errors\ApiError;
use ScreenshotCenter\Errors\TimeoutError;
use ScreenshotCenter\Errors\ScreenshotFailedError;

class ClientTest extends TestCase
{
    // ── Fixtures ──────────────────────────────────────────────────────────────

    private static array $screenshot = [
        'id' => 1001, 'status' => 'finished', 'url' => 'https://example.com',
        'final_url' => 'https://example.com/', 'error' => null, 'cost' => 1,
        'tag' => [], 'created_at' => '2026-01-01T00:00:00Z',
        'finished_at' => '2026-01-01T00:00:05Z', 'country' => 'us',
        'has_html' => false, 'has_pdf' => false, 'has_video' => false,
        'shots' => 1, 'html' => false, 'pdf' => false, 'video' => false,
    ];

    private static array $batch = [
        'id' => 2001, 'status' => 'finished', 'count' => 3,
        'processed' => 3, 'failed' => 0,
    ];

    private static array $account = ['balance' => 500];

    private function jsonResp(mixed $data, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, json_encode(['success' => true, 'data' => $data]), 'application/json');
    }

    private function errorResp(string $message, int $status, ?string $code = null): HttpResponse
    {
        return new HttpResponse($status, json_encode(['success' => false, 'error' => $message, 'code' => $code]), 'application/json');
    }

    private function binaryResp(string $content, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, $content, 'image/png');
    }

    private function makeClient(array $responses): Client
    {
        $queue = $responses;
        $transport = function () use (&$queue): HttpResponse {
            return array_shift($queue);
        };
        return new Client('test-key', 'https://api.screenshotcenter.com/api/v1', 30, $transport);
    }

    // ── Constructor ───────────────────────────────────────────────────────────

    public function testRaisesOnEmptyApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client('');
    }

    public function testDefaultBaseUrl(): void
    {
        $client = new Client('key');
        $this->assertStringContainsString('api.screenshotcenter.com', (function () { return $this->baseUrl; })->call($client));
    }

    public function testCustomBaseUrl(): void
    {
        $client = new Client('key', 'http://localhost:3000/api/v1');
        $baseUrl = (function () { return $this->baseUrl; })->call($client);
        $this->assertStringContainsString('localhost', $baseUrl);
    }

    public function testTrailingSlashStripped(): void
    {
        $client  = new Client('key', 'http://example.com/api/v1/');
        $baseUrl = (function () { return $this->baseUrl; })->call($client);
        $this->assertStringEndsNotWith('/', $baseUrl);
    }

    public function testNamespacesAttached(): void
    {
        $client = new Client('key');
        $this->assertInstanceOf(\ScreenshotCenter\ScreenshotNamespace::class, $client->screenshot);
        $this->assertInstanceOf(\ScreenshotCenter\BatchNamespace::class, $client->batch);
        $this->assertInstanceOf(\ScreenshotCenter\AccountNamespace::class, $client->account);
    }

    // ── screenshot.create ─────────────────────────────────────────────────────

    public function testCreateReturnsScreenshot(): void
    {
        $client = $this->makeClient([$this->jsonResp(self::$screenshot)]);
        $result = $client->screenshot->create('https://example.com');
        $this->assertEquals(1001, $result['id']);
        $this->assertEquals('finished', $result['status']);
    }

    public function testCreateSendsUrlAndKey(): void
    {
        $called = null;
        $transport = function ($m, $url) use (&$called) {
            $called = $url;
            return $this->jsonResp(self::$screenshot);
        };
        $client = new Client('test-key', 'https://api.screenshotcenter.com/api/v1', 30, $transport);
        $client->screenshot->create('https://example.com');
        $this->assertStringContainsString('url=https', $called);
        $this->assertStringContainsString('key=test-key', $called);
    }

    public function testCreatePassesOptionalParams(): void
    {
        $called = null;
        $transport = function ($m, $url) use (&$called) {
            $called = $url;
            return $this->jsonResp(self::$screenshot);
        };
        $client = new Client('test-key', 'https://api.screenshotcenter.com/api/v1', 30, $transport);
        $client->screenshot->create('https://example.com', ['country' => 'fr', 'shots' => 3]);
        $this->assertStringContainsString('country=fr', $called);
        $this->assertStringContainsString('shots=3', $called);
    }

    public function testCreatePassesUnknownFutureParams(): void
    {
        $called = null;
        $transport = function ($m, $url) use (&$called) {
            $called = $url;
            return $this->jsonResp(self::$screenshot);
        };
        $client = new Client('test-key', 'https://api.screenshotcenter.com/api/v1', 30, $transport);
        $client->screenshot->create('https://example.com', ['future_param' => 'xyz']);
        $this->assertStringContainsString('future_param=xyz', $called);
    }

    public function testCreateRaisesOnMissingUrl(): void
    {
        $client = new Client('key');
        $this->expectException(\InvalidArgumentException::class);
        $client->screenshot->create('');
    }

    public function testCreateRaisesApiErrorOn401(): void
    {
        $client = $this->makeClient([$this->errorResp('Unauthorized', 401)]);
        $this->expectException(ApiError::class);
        try {
            $client->screenshot->create('https://example.com');
        } catch (ApiError $e) {
            $this->assertEquals(401, $e->status);
            throw $e;
        }
    }

    public function testCreateRaisesApiErrorWithFields(): void
    {
        $body = json_encode(['success' => false, 'error' => 'Validation failed', 'code' => 'VALIDATION_ERROR', 'fields' => ['url' => ['Invalid URL']]]);
        $resp = new HttpResponse(422, $body, 'application/json');
        $client = $this->makeClient([$resp]);
        $this->expectException(ApiError::class);
        try {
            $client->screenshot->create('not-a-url');
        } catch (ApiError $e) {
            $this->assertEquals(422, $e->status);
            $this->assertEquals(['url' => ['Invalid URL']], $e->fields);
            throw $e;
        }
    }

    // ── screenshot.info ───────────────────────────────────────────────────────

    public function testInfoReturnsScreenshot(): void
    {
        $client = $this->makeClient([$this->jsonResp(self::$screenshot)]);
        $result = $client->screenshot->info(1001);
        $this->assertEquals(1001, $result['id']);
    }

    public function testInfoSendsIdParam(): void
    {
        $called = null;
        $transport = function ($m, $url) use (&$called) {
            $called = $url;
            return $this->jsonResp(self::$screenshot);
        };
        $client = new Client('key', 'https://api.screenshotcenter.com/api/v1', 30, $transport);
        $client->screenshot->info(1001);
        $this->assertStringContainsString('id=1001', $called);
    }

    public function testInfoRaisesOn404(): void
    {
        $client = $this->makeClient([$this->errorResp('Not found', 404)]);
        $this->expectException(ApiError::class);
        try {
            $client->screenshot->info(999);
        } catch (ApiError $e) {
            $this->assertEquals(404, $e->status);
            throw $e;
        }
    }

    // ── screenshot.list ───────────────────────────────────────────────────────

    public function testListReturnsArray(): void
    {
        $client = $this->makeClient([$this->jsonResp([self::$screenshot])]);
        $result = $client->screenshot->list();
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    public function testListPassesParams(): void
    {
        $called = null;
        $transport = function ($m, $url) use (&$called) {
            $called = $url;
            return $this->jsonResp([]);
        };
        $client = new Client('key', 'https://api.screenshotcenter.com/api/v1', 30, $transport);
        $client->screenshot->list(['limit' => 5, 'offset' => 10]);
        $this->assertStringContainsString('limit=5', $called);
        $this->assertStringContainsString('offset=10', $called);
    }

    // ── screenshot.search ─────────────────────────────────────────────────────

    public function testSearchReturnsArray(): void
    {
        $client = $this->makeClient([$this->jsonResp([self::$screenshot])]);
        $result = $client->screenshot->search('example.com');
        $this->assertIsArray($result);
    }

    public function testSearchSendsUrlParam(): void
    {
        $called = null;
        $transport = function ($m, $url) use (&$called) {
            $called = $url;
            return $this->jsonResp([]);
        };
        $client = new Client('key', 'https://api.screenshotcenter.com/api/v1', 30, $transport);
        $client->screenshot->search('example.com');
        $this->assertStringContainsString('url=example.com', $called);
    }

    public function testSearchRaisesOnMissingUrl(): void
    {
        $client = new Client('key');
        $this->expectException(\InvalidArgumentException::class);
        $client->screenshot->search('');
    }

    // ── screenshot.thumbnail ─────────────────────────────────────────────────

    public function testThumbnailReturnsBytes(): void
    {
        $client = $this->makeClient([$this->binaryResp('PNG-DATA')]);
        $result = $client->screenshot->thumbnail(1001);
        $this->assertEquals('PNG-DATA', $result);
    }

    public function testThumbnailPassesOptions(): void
    {
        $called = null;
        $transport = function ($m, $url) use (&$called) {
            $called = $url;
            return $this->binaryResp('x');
        };
        $client = new Client('key', 'https://api.screenshotcenter.com/api/v1', 30, $transport);
        $client->screenshot->thumbnail(1001, ['width' => 400, 'shot' => 2]);
        $this->assertStringContainsString('width=400', $called);
        $this->assertStringContainsString('shot=2', $called);
    }

    // ── save helpers ─────────────────────────────────────────────────────────

    public function testSaveImageWritesFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sc_') . '.png';
        $client  = $this->makeClient([$this->binaryResp('PNG-CONTENT')]);
        $client->screenshot->saveImage(1001, $tmpFile);
        $this->assertFileExists($tmpFile);
        $this->assertEquals('PNG-CONTENT', file_get_contents($tmpFile));
        unlink($tmpFile);
    }

    public function testSaveImageCreatesDirectories(): void
    {
        $tmpDir  = sys_get_temp_dir() . '/sc_test_' . uniqid();
        $tmpFile = "{$tmpDir}/a/b/shot.png";
        $client  = $this->makeClient([$this->binaryResp('PNG')]);
        $client->screenshot->saveImage(1001, $tmpFile);
        $this->assertFileExists($tmpFile);
        @unlink($tmpFile);
        @rmdir("{$tmpDir}/a/b");
        @rmdir("{$tmpDir}/a");
        @rmdir($tmpDir);
    }

    public function testSavePdfWritesFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sc_') . '.pdf';
        $client  = $this->makeClient([$this->binaryResp('%PDF-1.4')]);
        $client->screenshot->savePdf(1001, $tmpFile);
        $this->assertEquals('%PDF-1.4', file_get_contents($tmpFile));
        unlink($tmpFile);
    }

    public function testSaveHtmlWritesFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sc_') . '.html';
        $client  = $this->makeClient([$this->binaryResp('<html></html>')]);
        $client->screenshot->saveHtml(1001, $tmpFile);
        $this->assertEquals('<html></html>', file_get_contents($tmpFile));
        unlink($tmpFile);
    }

    // ── steps / trackers serialization ─────────────────────────────────────

    public function testStepsSerializedAsJson(): void
    {
        $called = null;
        $steps = [['command' => 'click', 'element' => '#accept'], ['command' => 'sleep', 'value' => 2]];
        $transport = function ($m, $url) use (&$called) {
            $called = $url;
            return $this->jsonResp(self::$screenshot);
        };
        $client = new Client('test-key', 'https://api.screenshotcenter.com/api/v1', 30, $transport);
        $client->screenshot->create('https://example.com', ['steps' => $steps]);
        $parts = parse_url($called);
        parse_str($parts['query'], $query);
        $decoded = json_decode($query['steps'], true);
        $this->assertEquals($steps, $decoded);
    }

    public function testTrackersSerializedAsJson(): void
    {
        $called = null;
        $trackers = [['id' => 'ga', 'name' => 'GA', 'value' => 'UA-12345']];
        $transport = function ($m, $url) use (&$called) {
            $called = $url;
            return $this->jsonResp(self::$screenshot);
        };
        $client = new Client('test-key', 'https://api.screenshotcenter.com/api/v1', 30, $transport);
        $client->screenshot->create('https://example.com', ['trackers' => $trackers]);
        $parts = parse_url($called);
        parse_str($parts['query'], $query);
        $decoded = json_decode($query['trackers'], true);
        $this->assertEquals($trackers, $decoded);
    }

    public function testPrimitiveArrayExpandedAsRepeatedKeys(): void
    {
        $called = null;
        $transport = function ($m, $url) use (&$called) {
            $called = $url;
            return $this->jsonResp(self::$screenshot);
        };
        $client = new Client('test-key', 'https://api.screenshotcenter.com/api/v1', 30, $transport);
        $client->screenshot->create('https://example.com', ['tag' => ['homepage', 'prod']]);
        $this->assertStringContainsString('tag=homepage', $called);
        $this->assertStringContainsString('tag=prod', $called);
    }

    public function testStepsNotStringifiedAsPhpArray(): void
    {
        $called = null;
        $transport = function ($m, $url) use (&$called) {
            $called = $url;
            return $this->jsonResp(self::$screenshot);
        };
        $client = new Client('test-key', 'https://api.screenshotcenter.com/api/v1', 30, $transport);
        $client->screenshot->create('https://example.com', ['steps' => [['command' => 'click']]]);
        $this->assertStringNotContainsString('Array', $called);
    }

    public function testStepsProducesValidJson(): void
    {
        $called = null;
        $steps = [['command' => 'click', 'element' => 'button']];
        $transport = function ($m, $url) use (&$called) {
            $called = $url;
            return $this->jsonResp(self::$screenshot);
        };
        $client = new Client('test-key', 'https://api.screenshotcenter.com/api/v1', 30, $transport);
        $client->screenshot->create('https://example.com', ['steps' => $steps]);
        $parts = parse_url($called);
        parse_str($parts['query'], $query);
        $this->assertNotNull(json_decode($query['steps'], true));
    }

    // ── waitFor ──────────────────────────────────────────────────────────────

    public function testWaitForResolvesOnFinished(): void
    {
        $finished = array_merge(self::$screenshot, ['status' => 'finished']);
        $client   = $this->makeClient([$this->jsonResp($finished)]);
        $result   = $client->waitFor(1001);
        $this->assertEquals('finished', $result['status']);
    }

    public function testWaitForPollsUntilFinished(): void
    {
        $processing = array_merge(self::$screenshot, ['status' => 'processing']);
        $finished   = array_merge(self::$screenshot, ['status' => 'finished']);
        $client     = $this->makeClient([
            $this->jsonResp($processing),
            $this->jsonResp($processing),
            $this->jsonResp($finished),
        ]);
        $result = $client->waitFor(1001, 0.001);
        $this->assertEquals('finished', $result['status']);
    }

    public function testWaitForRaisesOnErrorStatus(): void
    {
        $error  = array_merge(self::$screenshot, ['status' => 'error', 'error' => 'DNS failure']);
        $client = $this->makeClient([$this->jsonResp($error)]);
        $this->expectException(ScreenshotFailedError::class);
        try {
            $client->waitFor(1001);
        } catch (ScreenshotFailedError $e) {
            $this->assertEquals(1001, $e->screenshotId);
            $this->assertEquals('DNS failure', $e->error);
            throw $e;
        }
    }

    public function testWaitForRaisesTimeoutError(): void
    {
        $processing = array_merge(self::$screenshot, ['status' => 'processing']);
        $client     = $this->makeClient(array_fill(0, 10, $this->jsonResp($processing)));
        $this->expectException(TimeoutError::class);
        $client->waitFor(1001, 0.001, 0.001);
    }

    // ── batch.create ─────────────────────────────────────────────────────────

    public function testBatchCreateFromArray(): void
    {
        $client = $this->makeClient([$this->jsonResp(self::$batch)]);
        $result = $client->batch->create(['https://example.com', 'https://example.org'], 'us');
        $this->assertEquals(2001, $result['id']);
    }

    public function testBatchCreateFromString(): void
    {
        $client = $this->makeClient([$this->jsonResp(self::$batch)]);
        $result = $client->batch->create("https://example.com\nhttps://example.org", 'us');
        $this->assertEquals(2001, $result['id']);
    }

    public function testBatchCreateSendsMultipartPost(): void
    {
        $capturedMethod = null;
        $transport = function ($method) use (&$capturedMethod) {
            $capturedMethod = $method;
            return $this->jsonResp(self::$batch);
        };
        $client = new Client('key', 'https://api.screenshotcenter.com/api/v1', 30, $transport);
        $client->batch->create(['https://example.com'], 'us');
        $this->assertEquals('POST', $capturedMethod);
    }

    public function testBatchCreateRaisesOnMissingCountry(): void
    {
        $client = new Client('key');
        $this->expectException(\InvalidArgumentException::class);
        $client->batch->create(['https://example.com'], '');
    }

    // ── batch.waitFor ─────────────────────────────────────────────────────────

    public function testBatchWaitForResolvesOnFinished(): void
    {
        $client = $this->makeClient([$this->jsonResp(self::$batch)]);
        $result = $client->batch->waitFor(2001);
        $this->assertEquals('finished', $result['status']);
    }

    public function testBatchWaitForResolvesOnError(): void
    {
        $error  = array_merge(self::$batch, ['status' => 'error']);
        $client = $this->makeClient([$this->jsonResp($error)]);
        $result = $client->batch->waitFor(2001);
        $this->assertEquals('error', $result['status']);
    }

    public function testBatchWaitForRaisesTimeout(): void
    {
        $processing = array_merge(self::$batch, ['status' => 'processing']);
        $client     = $this->makeClient(array_fill(0, 10, $this->jsonResp($processing)));
        $this->expectException(TimeoutError::class);
        $client->batch->waitFor(2001, 0.001, 0.001);
    }

    // ── account.info ─────────────────────────────────────────────────────────

    public function testAccountInfoReturnsBalance(): void
    {
        $client = $this->makeClient([$this->jsonResp(self::$account)]);
        $result = $client->account->info();
        $this->assertEquals(500, $result['balance']);
    }

    public function testAccountInfoSendsKey(): void
    {
        $called = null;
        $transport = function ($m, $url) use (&$called) {
            $called = $url;
            return $this->jsonResp(self::$account);
        };
        $client = new Client('my-key', 'https://api.screenshotcenter.com/api/v1', 30, $transport);
        $client->account->info();
        $this->assertStringContainsString('key=my-key', $called);
    }

    // ── Error classes ─────────────────────────────────────────────────────────

    public function testApiErrorProperties(): void
    {
        $e = new ApiError('Bad request', 400, 'INVALID_PARAMS', ['url' => ['required']]);
        $this->assertEquals(400, $e->status);
        $this->assertEquals('INVALID_PARAMS', $e->code);
        $this->assertEquals(['url' => ['required']], $e->fields);
        $this->assertEquals('Bad request', $e->getMessage());
    }

    public function testTimeoutErrorProperties(): void
    {
        $e = new TimeoutError(1001, 30000);
        $this->assertEquals(1001, $e->screenshotId);
        $this->assertEquals(30000, $e->timeoutMs);
        $this->assertStringContainsString('1001', $e->getMessage());
    }

    public function testScreenshotFailedErrorProperties(): void
    {
        $e = new ScreenshotFailedError(1001, 'DNS failure');
        $this->assertEquals(1001, $e->screenshotId);
        $this->assertEquals('DNS failure', $e->error);
        $this->assertStringContainsString('DNS failure', $e->getMessage());
    }
}
