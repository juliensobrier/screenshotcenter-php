<?php

declare(strict_types=1);

namespace ScreenshotCenter;

final class HttpResponse
{
    /** @var int */
    public $status;
    /** @var string */
    public $body;
    /** @var string */
    public $contentType;

    public function __construct(int $status, string $body, string $contentType)
    {
        $this->status      = $status;
        $this->body        = $body;
        $this->contentType = $contentType;
    }
}
