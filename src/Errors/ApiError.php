<?php

declare(strict_types=1);

namespace ScreenshotCenter\Errors;

class ApiError extends \RuntimeException
{
    /** @var int */
    public $status;
    /** @var string|null */
    public $code;
    /** @var array */
    public $fields;

    public function __construct(string $message, int $status, ?string $code = null, array $fields = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->code   = $code;
        $this->fields = $fields;
    }
}
