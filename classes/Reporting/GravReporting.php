<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Reporting;

use Grav\Common\Grav;
use Grav\Plugin\SupertextTranslation\PluginVersion;
use Grav\Plugin\SupertextTranslation\Settings;
use Supertext\GravTranslation\Sdk\V1\InstallationReporter;

/**
 * Builds the plugin's Reporting from what Grav knows: the site's base URL, the Grav version, the
 * API plugin's route (for the ping URL) and user/data for the state file.
 */
final class GravReporting
{
    public static function create(Grav $grav, Settings $settings): Reporting
    {
        $siteUrl = self::siteUrl($grav);

        return new Reporting(
            store: new StateStore(self::stateFile($grav)),
            enabled: InstallationReporter::reportsEnabled($settings->versionReports),
            siteUrl: $siteUrl,
            pluginVersion: PluginVersion::get(),
            gravVersion: \defined('GRAV_VERSION') ? (string)\constant('GRAV_VERSION') : null,
            callbackUrl: $siteUrl . self::apiBase($grav) . Reporting::PING_PATH,
            apiBaseUrl: $settings->apiUrl,
        );
    }

    /** The site's base URL without a trailing slash, e.g. https://www.example.ch or https://example.ch/grav. */
    public static function siteUrl(Grav $grav): string
    {
        $custom = trim((string)$grav['config']->get('system.custom_base_url', ''));
        if (preg_match('#^https?://[^/]#i', $custom) === 1) {
            return rtrim($custom, '/');
        }

        return rtrim((string)$grav['uri']->rootUrl(true), '/');
    }

    /** The API plugin's base path, e.g. /api/v1. */
    public static function apiBase(Grav $grav): string
    {
        $base = (string)$grav['config']->get('plugins.api.route', '/api');
        $prefix = (string)$grav['config']->get('plugins.api.version_prefix', 'v1');

        return '/' . trim($base, '/') . '/' . trim($prefix, '/');
    }

    private static function stateFile(Grav $grav): string
    {
        $dir = '';
        try {
            $found = $grav['locator']->findResource('user-data://', true, true);
            $dir = is_string($found) ? $found : '';
        } catch (\Throwable) {
            // No stream: fall back to the conventional location.
        }
        if ($dir === '') {
            $dir = (\defined('GRAV_ROOT') ? (string)\constant('GRAV_ROOT') : getcwd()) . '/user/data';
        }

        return rtrim($dir, '/') . '/supertext-translation/reporter.json';
    }
}
