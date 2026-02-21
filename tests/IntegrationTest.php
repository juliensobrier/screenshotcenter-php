<?php

declare(strict_types=1);

namespace ScreenshotCenter\Tests;

use PHPUnit\Framework\TestCase;
use ScreenshotCenter\Client;

/**
 * Integration tests run only when SCREENSHOTCENTER_API_KEY is set.
 *
 * Run unit tests only (default):
 *   vendor/bin/phpunit
 *
 * Run against a local instance:
 *   SCREENSHOTCENTER_API_KEY=your_key \
 *   SCREENSHOTCENTER_BASE_URL=http://localhost:3000/api/v1 \
 *   vendor/bin/phpunit --testsuite integration
 */
class IntegrationTest extends TestCase
{
    private ?Client $client = null;
    /** @var int[] */
    private array $createdIds = [];

    protected function setUp(): void
    {
        $apiKey = getenv('SCREENSHOTCENTER_API_KEY');
        if (!$apiKey) {
            $this->markTestSkipped('SCREENSHOTCENTER_API_KEY not set');
        }
        $baseUrl      = getenv('SCREENSHOTCENTER_BASE_URL') ?: 'https://api.screenshotcenter.com/api/v1';
        $this->client = new Client($apiKey, $baseUrl, 30);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds as $id) {
            try {
                $this->client->screenshot->delete($id, 'all');
            } catch (\Throwable $e) {
                // best-effort cleanup
            }
        }
    }

    private function createAndWait(string $url, array $params = [], float $timeout = 110.0): array
    {
        $shot = $this->client->screenshot->create($url, $params);
        $this->assertArrayHasKey('id', $shot);
        $this->createdIds[] = $shot['id'];
        return $this->client->waitFor($shot['id'], 3.0, $timeout);
    }

    // ── account ──────────────────────────────────────────────────────────────

    public function testAccountInfo(): void
    {
        $info = $this->client->account->info();
        $this->assertArrayHasKey('balance', $info);
        $this->assertIsNumeric($info['balance']);
    }

    // ── screenshot.create ─────────────────────────────────────────────────────

    public function testCreateAndWait(): void
    {
        $result = $this->createAndWait('https://example.com');
        $this->assertEquals('finished', $result['status']);
        $this->assertNotEmpty($result['url']);
    }

    public function testCreateWithGeoParams(): void
    {
        $result = $this->createAndWait('https://example.com', ['country' => 'us', 'lang' => 'en-US']);
        $this->assertEquals('finished', $result['status']);
    }

    public function testCreateInvalidUrl(): void
    {
        $this->expectException(\ScreenshotCenter\Errors\ApiError::class);
        $this->client->screenshot->create('not-valid-url-xyz');
    }

    // ── screenshot.info ───────────────────────────────────────────────────────

    public function testInfo(): void
    {
        $shot = $this->client->screenshot->create('https://example.com');
        $this->createdIds[] = $shot['id'];
        $info = $this->client->screenshot->info($shot['id']);
        $this->assertEquals($shot['id'], $info['id']);
    }

    // ── screenshot.list ───────────────────────────────────────────────────────

    public function testList(): void
    {
        $list = $this->client->screenshot->list(['limit' => 5]);
        $this->assertIsArray($list);
    }

    // ── screenshot.search ─────────────────────────────────────────────────────

    public function testSearch(): void
    {
        $results = $this->client->screenshot->search('example.com', ['limit' => 5]);
        $this->assertIsArray($results);
    }

    // ── save helpers ─────────────────────────────────────────────────────────

    public function testSaveImage(): void
    {
        $result  = $this->createAndWait('https://example.com');
        $tmpFile = tempnam(sys_get_temp_dir(), 'sc_') . '.png';
        $this->client->screenshot->saveImage($result['id'], $tmpFile);
        $this->assertFileExists($tmpFile);
        $this->assertGreaterThan(0, filesize($tmpFile));
        unlink($tmpFile);
    }

    // ── invalid API key ───────────────────────────────────────────────────────

    public function testInvalidApiKey(): void
    {
        $bad = new Client('invalid-key');
        $this->expectException(\ScreenshotCenter\Errors\ApiError::class);
        $bad->account->info();
    }

    // ── batch ─────────────────────────────────────────────────────────────────

    public function testBatchCreateAndWait(): void
    {
        // Requires batch worker service to be running
        $urls  = ['https://example.com', 'https://example.org'];
        $batch = $this->client->batch->create($urls, 'us');
        $this->assertArrayHasKey('id', $batch);
        $result = $this->client->batch->waitFor($batch['id'], 3.0, 110.0);
        $this->assertContains($result['status'], ['finished', 'error']);
    }
}
