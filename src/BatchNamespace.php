<?php

declare(strict_types=1);

namespace ScreenshotCenter;

use ScreenshotCenter\Errors\TimeoutError;

class BatchNamespace
{
    /** @var Client */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /** @param string|array|resource $urls */
    public function create($urls, string $country, array $params = []): array
    {
        if (empty($country)) {
            throw new \InvalidArgumentException('"country" is required');
        }
        if (is_array($urls)) {
            $content = implode("\n", $urls);
        } elseif (is_resource($urls)) {
            $content = stream_get_contents($urls);
        } else {
            $content = (string)$urls;
        }
        $boundary = bin2hex(random_bytes(16));
        $body     = $this->buildMultipart($boundary, array_merge(['country' => $country], $params), $content);
        return $this->client->post('/batch/create', $body, "multipart/form-data; boundary={$boundary}");
    }

    public function info(int $id): array
    {
        return $this->client->get('/batch/info', ['id' => $id]);
    }

    public function list(array $params = []): array
    {
        return $this->client->get('/batch/list', $params);
    }

    public function cancel(int $id): void
    {
        $this->client->post('/batch/cancel', json_encode(['id' => $id]), 'application/json');
    }

    public function download(int $id): string
    {
        return $this->client->getBytes('/batch/download', ['id' => $id]);
    }

    public function saveZip(int $id, string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $this->download($id));
    }

    public function waitFor(int $id, float $interval = 2.0, float $timeout = 120.0): array
    {
        $deadline = microtime(true) + $timeout;
        while (true) {
            $b = $this->info($id);
            if (in_array($b['status'] ?? '', ['finished', 'error'], true)) {
                return $b;
            }
            if (microtime(true) + $interval > $deadline) {
                throw new TimeoutError($id, (int)($timeout * 1000));
            }
            usleep((int)($interval * 1000000));
        }
    }

    private function buildMultipart(string $boundary, array $fields, string $fileContent): string
    {
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
            $body .= "{$value}\r\n";
        }
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"urls.txt\"\r\n";
        $body .= "Content-Type: text/plain\r\n\r\n";
        $body .= $fileContent . "\r\n";
        $body .= "--{$boundary}--\r\n";
        return $body;
    }
}
