<?php

declare(strict_types=1);

namespace ScreenshotCenter\Errors;

class TimeoutError extends \RuntimeException
{
    /** @var int */
    public $screenshotId;
    /** @var int */
    public $timeoutMs;

    public function __construct(int $screenshotId, int $timeoutMs)
    {
        parent::__construct("Screenshot {$screenshotId} did not complete within {$timeoutMs}ms");
        $this->screenshotId = $screenshotId;
        $this->timeoutMs    = $timeoutMs;
    }
}
