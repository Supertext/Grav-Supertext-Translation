<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Controller;

use Grav\Framework\Psr7\Response;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\SupertextTranslation\Reporting\Reporting;
use Grav\Plugin\SupertextTranslation\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Supertext\GravTranslation\PluginHealth\InstallationReporter;

/**
 * GET /api/v1/supertext/ping?challenge=… — the public endpoint the Supertext Commando Center
 * calls to see whether this site can be reached. No login (registered as a public route in
 * onApiCollectPublicRoutes); the answer is the SDK's as it is: 200 with the challenge, 400 for
 * a bad challenge, 404 while version reports are off.
 */
final class PingController extends AbstractApiController
{
    public function ping(ServerRequestInterface $request): ResponseInterface
    {
        $settings = Settings::fromConfig((array)$this->config->get('plugins.supertext-translation', []));
        $challenge = $request->getQueryParams()['challenge'] ?? null;
        $ping = Reporting::ping(is_string($challenge) ? $challenge : null, InstallationReporter::reportsEnabled($settings->versionReports));

        return new Response($ping->status, $ping->headers, $ping->body);
    }
}
