<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Tests;

use Supertext\GravTranslation\Sdk\Http\HttpRequest;
use Supertext\GravTranslation\Sdk\Http\HttpResponse;
use Supertext\GravTranslation\Sdk\Http\HttpTransport;

/** Answers the key check (GET features) like Supertext and the report like the Commando Center. */
final class FakeCenter implements HttpTransport
{
    /** @var list<HttpRequest> */
    public array $requests = [];

    /** @var list<array<string, mixed>> */
    public array $reports = [];

    public int $keyStatus = 200;
    public int $reportStatus = 202;

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        if ($request->method === 'POST' && str_ends_with($request->url, '/report')) {
            $this->reports[] = json_decode((string)$request->body, true, 16, JSON_THROW_ON_ERROR);
            return new HttpResponse($this->reportStatus, '{"ok":true}', ['content-type' => 'application/json']);
        }

        return new HttpResponse($this->keyStatus, $this->keyStatus === 200 ? '{"features":[]}' : '{"error_code":"UNAUTHORIZED"}', ['content-type' => 'application/json']);
    }

    /** @return list<string> */
    public function calls(): array
    {
        return array_map(static fn (HttpRequest $r): string => $r->method . ' ' . $r->url, $this->requests);
    }
}
