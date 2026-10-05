<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Api;

/** Minimal HTTP transport, so tests can replace the network. */
interface Transport
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string} header names in lower case
     */
    public function send(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array;
}
