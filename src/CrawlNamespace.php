<?php

declare(strict_types=1);

namespace ScreenshotCenter;

use ScreenshotCenter\Errors\TimeoutError;

class CrawlNamespace
{
    /** @var Client */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function create(string $url, string $domain, int $maxUrls, array $params = []): array
    {
        $body = array_merge(
            ['url' => $url, 'domain' => $domain, 'maxUrls' => $maxUrls],
            $params
        );
        return $this->client->post('/crawl/create', json_encode($body), 'application/json');
    }

    public function info(int $id): array
    {
        return $this->client->get('/crawl/info', ['id' => $id]);
    }

    public function list(array $params = []): array
    {
        return $this->client->get('/crawl/list', $params);
    }

    public function cancel(int $id): void
    {
        $this->client->post('/crawl/cancel', json_encode(['id' => $id]), 'application/json');
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
}
