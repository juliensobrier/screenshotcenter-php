<?php

declare(strict_types=1);

namespace ScreenshotCenter;

class ScreenshotNamespace
{
    /** @var Client */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function create(string $url, array $params = []): array
    {
        if (empty($url)) {
            throw new \InvalidArgumentException('"url" is required');
        }
        return $this->client->get('/screenshot/create', array_merge(['url' => $url], $params));
    }

    public function info(int $id): array
    {
        return $this->client->get('/screenshot/info', ['id' => $id]);
    }

    public function list(array $params = []): array
    {
        return $this->client->get('/screenshot/list', $params);
    }

    public function search(string $url, array $params = []): array
    {
        if (empty($url)) {
            throw new \InvalidArgumentException('"url" is required');
        }
        return $this->client->get('/screenshot/search', array_merge(['url' => $url], $params));
    }

    public function thumbnail(int $id, array $params = []): string
    {
        return $this->client->getBytes('/screenshot/thumbnail', array_merge(['id' => $id], $params));
    }

    public function html(int $id): string
    {
        return $this->client->getBytes('/screenshot/html', ['id' => $id]);
    }

    public function pdf(int $id): string
    {
        return $this->client->getBytes('/screenshot/pdf', ['id' => $id]);
    }

    public function video(int $id): string
    {
        return $this->client->getBytes('/screenshot/video', ['id' => $id]);
    }

    public function delete(int $id, string $data = 'all'): void
    {
        $this->client->get('/screenshot/delete', ['id' => $id, 'data' => $data]);
    }

    // ── File-save helpers ────────────────────────────────────────────────────

    public function saveImage(int $id, string $path, array $params = []): void
    {
        $bytes = $this->thumbnail($id, $params);
        $this->writeFile($path, $bytes);
    }

    public function savePdf(int $id, string $path): void
    {
        $this->writeFile($path, $this->pdf($id));
    }

    public function saveHtml(int $id, string $path): void
    {
        $this->writeFile($path, $this->html($id));
    }

    public function saveVideo(int $id, string $path): void
    {
        $this->writeFile($path, $this->video($id));
    }

    public function saveAll(int $id, string $directory, string $basename = ''): array
    {
        $s    = $this->info($id);
        $stem = $basename ?: (string)$id;
        $dir  = rtrim($directory, '/');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $saved = ['image' => null, 'html' => null, 'pdf' => null, 'video' => null];

        if (($s['status'] ?? '') === 'finished') {
            $p = "{$dir}/{$stem}.png";
            $this->saveImage($id, $p);
            $saved['image'] = $p;
        }
        if (!empty($s['has_html'])) {
            $p = "{$dir}/{$stem}.html";
            $this->saveHtml($id, $p);
            $saved['html'] = $p;
        }
        if (!empty($s['has_pdf'])) {
            $p = "{$dir}/{$stem}.pdf";
            $this->savePdf($id, $p);
            $saved['pdf'] = $p;
        }
        if (!empty($s['has_video'])) {
            $ext = $s['video_format'] ?? 'webm';
            $p   = "{$dir}/{$stem}.{$ext}";
            $this->saveVideo($id, $p);
            $saved['video'] = $p;
        }
        return $saved;
    }

    private function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);
    }
}
