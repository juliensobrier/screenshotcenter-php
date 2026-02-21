<?php

declare(strict_types=1);

namespace ScreenshotCenter\Errors;

class ScreenshotFailedError extends \RuntimeException
{
    /** @var int */
    public $screenshotId;
    /** @var string|null */
    public $error;

    public function __construct(int $screenshotId, ?string $error = null)
    {
        parent::__construct("Screenshot {$screenshotId} failed: " . ($error ?? 'unknown error'));
        $this->screenshotId = $screenshotId;
        $this->error        = $error;
    }
}
