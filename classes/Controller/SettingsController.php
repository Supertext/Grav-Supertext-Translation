<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Controller;

use Grav\Common\Language\LanguageCodes;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\SupertextTranslation\PluginVersion;
use Grav\Plugin\SupertextTranslation\Reporting\GravReporting;
use Grav\Plugin\SupertextTranslation\Reporting\Reporting;
use Grav\Plugin\SupertextTranslation\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Supertext\GravTranslation\Sdk\V1\ApiKeyCheck;
use Supertext\GravTranslation\Sdk\V1\Languages;

/**
 * API routes behind the plugin's own fields on its settings page (admin-next/fields/):
 *
 *   GET  /api/v1/supertext/settings   site languages with their Supertext codes, the latest API
 *                                     key check and whether version reports are on
 *   POST /api/v1/supertext/check      checks the API key again ("Check again")
 *
 * Both need the right to read the configuration (api.config.read).
 */
final class SettingsController extends AbstractApiController
{
    private const PERMISSION = 'api.config.read';

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION);
        $settings = Settings::fromConfig((array)$this->config->get('plugins.supertext-translation', []));
        $reporting = GravReporting::create($this->grav, $settings);
        $check = $reporting->lastCheck();
        // The settings page is a back-end request that runs the plugin: look for an update afterwards.
        $reporting->runLater(Reporting::ON_REQUEST, $settings->apiKey);

        return ApiResponse::create($this->payload($settings, $reporting, $check));
    }

    public function check(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION);
        $settings = Settings::fromConfig((array)$this->config->get('plugins.supertext-translation', []));
        $reporting = GravReporting::create($this->grav, $settings);
        $result = $reporting->run(Reporting::TEST_CONNECTION, $settings->apiKey);

        return ApiResponse::create($this->payload($settings, $reporting, $result->check ?? $reporting->lastCheck()));
    }

    /** @return array<string, mixed> */
    private function payload(Settings $settings, Reporting $reporting, ?ApiKeyCheck $check): array
    {
        $language = $this->grav['language'];
        $codes = array_values(array_map('strval', (array)$language->getLanguages()));
        $source = $settings->sourceLanguage !== '' && in_array($settings->sourceLanguage, $codes, true)
            ? $settings->sourceLanguage
            : (string)($language->getDefault() ?: ($codes[0] ?? ''));

        $languages = [];
        foreach ($codes as $code) {
            $target = $settings->targetCodeOrNull($code);
            $languages[] = [
                'code' => $code,
                'name' => $this->languageName($code),
                'is_source' => $code === $source,
                // What the administrator chose, and what Supertext gets.
                'mapped' => $settings->languageCodes[strtolower($code)] ?? null,
                'exact' => Languages::matchTarget($code),
                'target' => $target,
                'source_code' => $code === $source ? $settings->sourceCodeOrNull($code) : null,
                'politeness_supported' => $target !== null ? Languages::supportsPoliteness($target) : null,
            ];
        }

        $env = getenv('SUPERTEXT_VERSION_REPORTS');

        return [
            'languages' => $languages,
            'targets' => Languages::TARGETS,
            'politeness' => $settings->politeness,
            'api_key_configured' => $settings->apiKey !== '',
            'key_check' => $check === null ? null : [
                'status' => $check->status,
                'checked_at' => $check->checkedAt,
                'http_status' => $check->httpStatus,
                'error_code' => $check->errorCode,
            ],
            'reports' => [
                'enabled' => $reporting->enabled,
                'setting' => $settings->versionReports ?? true,
                'environment' => is_string($env) && trim($env) !== '' ? trim($env) : null,
            ],
            'ping_url' => GravReporting::siteUrl($this->grav) . GravReporting::apiBase($this->grav) . Reporting::PING_PATH,
            'version' => PluginVersion::get(),
        ];
    }

    private function languageName(string $code): string
    {
        $name = LanguageCodes::getNativeName($code) ?: LanguageCodes::getName($code);
        return is_string($name) && $name !== '' ? $name : strtoupper($code);
    }
}
