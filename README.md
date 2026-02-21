# screenshotcenter-php

Official PHP SDK for the [ScreenshotCenter](https://screenshotcenter.com) API.

Capture web screenshots, PDFs, HTML snapshots, and videos at scale.

## Requirements

- PHP ≥ 7.2
- `ext-curl`
- `ext-json`

## Installation

```bash
composer require screenshotcenter/screenshotcenter
```

## Quick start

```php
use ScreenshotCenter\Client;

$client = new Client('your_api_key');

// Take a screenshot and wait for it to finish
$shot   = $client->screenshot->create('https://example.com');
$result = $client->waitFor($shot['id']);
echo $result['url'];          // final URL
echo $result['status'];       // "finished"
```

## Authentication

Pass your API key to the constructor:

```php
$client = new Client(getenv('SCREENSHOTCENTER_API_KEY'));
```

Get your key at <https://app.screenshotcenter.com>.

## Use cases

### Basic screenshot

```php
$shot = $client->screenshot->create('https://example.com');
```

### Geo-targeting

```php
$shot = $client->screenshot->create('https://example.com', [
    'country' => 'fr',
    'lang'    => 'fr-FR',
    'tz'      => 'Europe/Paris',
]);
```

### Full-page screenshot

```php
$shot = $client->screenshot->create('https://example.com', [
    'full_page' => true,
]);
```

### PDF

```php
$shot = $client->screenshot->create('https://example.com', ['pdf' => true]);
$done = $client->waitFor($shot['id']);
$client->screenshot->savePdf($done['id'], '/tmp/page.pdf');
```

### HTML snapshot

```php
$shot = $client->screenshot->create('https://example.com', ['html' => true]);
$done = $client->waitFor($shot['id']);
$client->screenshot->saveHtml($done['id'], '/tmp/page.html');
```

### Video

```php
$shot = $client->screenshot->create('https://example.com', [
    'video'        => true,
    'video_length' => 5,
]);
$done = $client->waitFor($shot['id']);
$client->screenshot->saveVideo($done['id'], '/tmp/page.webm');
```

### Multiple shots

```php
$shot = $client->screenshot->create('https://example.com', ['shots' => 5]);
$done = $client->waitFor($shot['id']);
// Thumbnail of shot 3
$client->screenshot->saveImage($done['id'], '/tmp/shot3.png', ['shot' => 3]);
```

### Save all artifacts

```php
$done  = $client->waitFor($shot['id']);
$files = $client->screenshot->saveAll($done['id'], '/tmp/screenshots');
echo $files['image'];  // /tmp/screenshots/1001.png
echo $files['pdf'];    // /tmp/screenshots/1001.pdf (if requested)
```

### Batch

```php
// Batch requires the batch worker service to be running
$urls  = ['https://example.com', 'https://example.org', 'https://example.net'];
$batch = $client->batch->create($urls, 'us');
$done  = $client->batch->waitFor($batch['id'], interval: 3.0, timeout: 120.0);
$client->batch->saveZip($done['id'], '/tmp/batch.zip');
```

### Credit balance

```php
$info = $client->account->info();
echo $info['balance'];  // available credits
```

### Error handling

```php
use ScreenshotCenter\Errors\ApiError;
use ScreenshotCenter\Errors\TimeoutError;
use ScreenshotCenter\Errors\ScreenshotFailedError;

try {
    $result = $client->waitFor($shot['id'], interval: 2.0, timeout: 60.0);
} catch (ScreenshotFailedError $e) {
    echo "Screenshot failed: {$e->error}";
} catch (TimeoutError $e) {
    echo "Timed out after {$e->timeoutMs}ms";
} catch (ApiError $e) {
    echo "API error {$e->status}: {$e->getMessage()}";
}
```

## API reference

### `Client`

```php
new Client(
    string $apiKey,
    string $baseUrl  = 'https://api.screenshotcenter.com/api/v1',
    int    $timeout  = 30,       // HTTP timeout (seconds)
    ?callable $transport = null  // Injectable transport for testing
)
```

### `$client->screenshot`

| Method | Description |
|--------|-------------|
| `create(string $url, array $params = []): array` | Create a screenshot |
| `info(int $id): array` | Get screenshot metadata |
| `list(array $params = []): array` | List screenshots |
| `search(string $url, array $params = []): array` | Search by URL |
| `thumbnail(int $id, array $params = []): string` | Raw image bytes |
| `html(int $id): string` | Raw HTML bytes |
| `pdf(int $id): string` | Raw PDF bytes |
| `video(int $id): string` | Raw video bytes |
| `delete(int $id, string $data = 'all'): void` | Delete a screenshot |
| `saveImage(int $id, string $path, array $params = []): void` | Save image to disk |
| `saveHtml(int $id, string $path): void` | Save HTML to disk |
| `savePdf(int $id, string $path): void` | Save PDF to disk |
| `saveVideo(int $id, string $path): void` | Save video to disk |
| `saveAll(int $id, string $dir, string $basename = ''): array` | Save all artifacts |

### `$client->batch`

| Method | Description |
|--------|-------------|
| `create($urls, string $country, array $params = []): array` | Create a batch |
| `info(int $id): array` | Get batch status |
| `list(array $params = []): array` | List batches |
| `cancel(int $id): void` | Cancel a batch |
| `download(int $id): string` | Download ZIP bytes |
| `saveZip(int $id, string $path): void` | Save ZIP to disk |
| `waitFor(int $id, float $interval = 2.0, float $timeout = 120.0): array` | Poll until done |

### `$client->account`

| Method | Description |
|--------|-------------|
| `info(): array` | Get account info (balance, plan, etc.) |

### `$client->waitFor(int $id, float $interval = 2.0, float $timeout = 120.0): array`

Poll a screenshot until `finished` or `error`.

## Testing

### Environment variables

| Variable | Description |
|----------|-------------|
| `SCREENSHOTCENTER_API_KEY` | Required for integration tests |
| `SCREENSHOTCENTER_BASE_URL` | Override base URL (default: production) |

### Running tests

```bash
# Unit tests only — no credentials needed
vendor/bin/phpunit

# Unit + integration tests against a local instance
SCREENSHOTCENTER_API_KEY=your_key \
SCREENSHOTCENTER_BASE_URL=http://localhost:3000/api/v1 \
vendor/bin/phpunit --testsuite integration
```

## License

MIT — see [LICENSE](LICENSE).
