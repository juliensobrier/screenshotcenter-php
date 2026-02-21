<?php

declare(strict_types=1);

namespace ScreenshotCenter;

use ScreenshotCenter\Errors\ApiError;
use ScreenshotCenter\Errors\ScreenshotFailedError;
use ScreenshotCenter\Errors\TimeoutError;

/**
 * ScreenshotCenter PHP SDK.
 *
 * @example
 * $client = new \ScreenshotCenter\Client('your_api_key');
 * $shot   = $client->screenshot->create('https://example.com');
 * $result = $client->waitFor($shot['id']);
 * echo $result['url'];
 */
class Client
{
    /** @var string */
    private $apiKey;
    /** @var string */
    private $baseUrl;
    /** @var int */
    private $timeout;
    /** @var callable */
    private $transport;

    /** @var ScreenshotNamespace */
    public $screenshot;
    /** @var BatchNamespace */
    public $batch;
    /** @var AccountNamespace */
    public $account;

    /**
     * @param callable|null $transport  Injectable HTTP transport for testing.
     *                                  Signature: fn(string $method, string $url, string $body, string $ct): HttpResponse
     */
    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://api.screenshotcenter.com/api/v1',
        int $timeout = 30,
        callable $transport = null
    ) {
        if (empty($apiKey)) {
            throw new \InvalidArgumentException('apiKey is required');
        }
        $this->apiKey    = $apiKey;
        $this->baseUrl   = rtrim($baseUrl, '/');
        $this->timeout   = $timeout;
        $this->transport = $transport ?? [$this, 'curlTransport'];

        $this->screenshot = new ScreenshotNamespace($this);
        $this->batch      = new BatchNamespace($this);
        $this->account    = new AccountNamespace($this);
    }

    // -------------------------------------------------------------------------
    // Internal helpers (used by namespaces)
    // -------------------------------------------------------------------------

    /** @internal */
    public function get(string $endpoint, array $params = []): array
    {
        $url  = $this->buildUrl($endpoint, $params);
        $resp = ($this->transport)('GET', $url, '', '');
        return $this->parseResponse($resp);
    }

    /** @internal */
    public function getBytes(string $endpoint, array $params = []): string
    {
        $url  = $this->buildUrl($endpoint, $params);
        $resp = ($this->transport)('GET', $url, '', '');
        if ($resp->status < 200 || $resp->status >= 300) {
            $data = json_decode($resp->body, true) ?? [];
            throw new ApiError(
                $data['error'] ?? "HTTP {$resp->status}",
                $resp->status,
                $data['code'] ?? null,
                $data['fields'] ?? []
            );
        }
        return $resp->body;
    }

    /** @internal */
    public function post(string $endpoint, string $body, string $contentType, array $params = []): array
    {
        $url  = $this->buildUrl($endpoint, $params);
        $resp = ($this->transport)('POST', $url, $body, $contentType);
        return $this->parseResponse($resp);
    }

    // -------------------------------------------------------------------------
    // Polling
    // -------------------------------------------------------------------------

    /**
     * Poll until a screenshot reaches finished or error.
     *
     * @throws ScreenshotFailedError
     * @throws TimeoutError
     */
    public function waitFor(int $id, float $interval = 2.0, float $timeout = 120.0): array
    {
        $deadline = microtime(true) + $timeout;
        while (true) {
            $s = $this->screenshot->info($id);
            if ($s['status'] === 'finished') {
                return $s;
            }
            if ($s['status'] === 'error') {
                throw new ScreenshotFailedError($id, $s['error'] ?? null);
            }
            if (microtime(true) + $interval > $deadline) {
                throw new TimeoutError($id, (int)($timeout * 1000));
            }
            usleep((int)($interval * 1000000));
        }
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    private function buildUrl(string $endpoint, array $params): string
    {
        $all = array_merge(['key' => $this->apiKey], $params);
        $parts = [];
        foreach ($all as $k => $v) {
            if ($v === null) continue;
            if (is_array($v)) {
                foreach ($v as $item) {
                    $parts[] = urlencode($k) . '=' . urlencode((string)$item);
                }
            } elseif (is_bool($v)) {
                $parts[] = urlencode($k) . '=' . ($v ? 'true' : 'false');
            } else {
                $parts[] = urlencode($k) . '=' . urlencode((string)$v);
            }
        }
        return $this->baseUrl . $endpoint . '?' . implode('&', $parts);
    }

    private function parseResponse(HttpResponse $resp): array
    {
        if ($resp->status < 200 || $resp->status >= 300) {
            $data = json_decode($resp->body, true) ?? [];
            throw new ApiError(
                $data['error'] ?? "HTTP {$resp->status}",
                $resp->status,
                $data['code'] ?? null,
                $data['fields'] ?? []
            );
        }
        $data = json_decode($resp->body, true);
        if (isset($data['success'])) {
            if (!$data['success']) {
                throw new ApiError(
                    $data['error'] ?? 'API request failed',
                    $resp->status,
                    $data['code'] ?? null,
                    $data['fields'] ?? []
                );
            }
            return $data['data'] ?? [];
        }
        return $data ?? [];
    }

    private function curlTransport(string $method, string $url, string $body, string $contentType): HttpResponse
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders) {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($header);
        });
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            if ($contentType) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: {$contentType}"]);
            }
        }
        $respBody = (string)curl_exec($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return new HttpResponse($status, $respBody, $responseHeaders['content-type'] ?? '');
    }
}
