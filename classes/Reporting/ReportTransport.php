<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Reporting;

use Supertext\GravTranslation\PluginHealth\Http\HttpRequest;
use Supertext\GravTranslation\PluginHealth\Http\HttpResponse;
use Supertext\GravTranslation\PluginHealth\Http\HttpTransport;
use Supertext\GravTranslation\PluginHealth\Http\TransportException;
use Supertext\GravTranslation\Sdk\Http\HttpRequest as SdkHttpRequest;
use Supertext\GravTranslation\Sdk\Http\HttpTransport as SdkHttpTransport;

/**
 * Sends Supertext Plugin Health's version reports through the SDK's transport given to Reporting
 * (tests: a fake). Plugin Health has its own HTTP types; this only converts them.
 */
final class ReportTransport implements HttpTransport
{
    public function __construct(private readonly SdkHttpTransport $transport)
    {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        try {
            $response = $this->transport->send(new SdkHttpRequest($request->method, $request->url, $request->headers, $request->body, $request->timeout));
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        return new HttpResponse($response->status, $response->body, $response->headers);
    }
}
