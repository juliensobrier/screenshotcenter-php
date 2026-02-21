<?php

declare(strict_types=1);

namespace ScreenshotCenter;

class AccountNamespace
{
    /** @var Client */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function info(): array
    {
        return $this->client->get('/account/info');
    }
}
