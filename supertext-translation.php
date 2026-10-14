<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\SupertextTranslation\Controller\PingController;
use Grav\Plugin\SupertextTranslation\Controller\SettingsController;
use Grav\Plugin\SupertextTranslation\Controller\TranslationController;
use Grav\Plugin\SupertextTranslation\Messages;
use Grav\Plugin\SupertextTranslation\Reporting\GravReporting;
use Grav\Plugin\SupertextTranslation\Reporting\Reporting;
use Grav\Plugin\SupertextTranslation\Settings;
use RocketTheme\Toolbox\Event\Event;

// The plugin's classes, and the Supertext SDK it ships (lib/supertext-sdk, own namespace,
// see docs/DEVELOPER.md → Bundled Supertext SDK).
spl_autoload_register(static function (string $class): void {
    foreach ([
        'Grav\\Plugin\\SupertextTranslation\\' => __DIR__ . '/classes/',
        'Supertext\\GravTranslation\\Sdk\\' => __DIR__ . '/lib/supertext-sdk/src/',
    ] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

/**
 * Supertext Translation for Grav 2.
 *
 * Adds a "Supertext" panel to the page editor of Admin2 (the toolbar button
 * with the languages icon), the API routes the panel and the settings page call,
 * and the Supertext Commando Center's version reports, API key check and public ping
 * (see docs/DEVELOPER.md → Version reports and API key check).
 */
class SupertextTranslationPlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            'onApiContextPanels' => ['onApiContextPanels', 0],
            'onApiCollectPublicRoutes' => ['onApiCollectPublicRoutes', 0],
            'onApiConfigUpdated' => ['onApiConfigUpdated', 0],
            'onAdminSave' => ['onAdminSave', 0],
            'onShutdown' => ['onShutdown', 0],
        ];
    }

    public function onApiRegisterRoutes(Event $event): void
    {
        $routes = $event['routes'];
        $routes->get('/supertext/status', [TranslationController::class, 'status']);
        $routes->post('/supertext/translate', [TranslationController::class, 'translate']);
        $routes->get('/supertext/settings', [SettingsController::class, 'show']);
        $routes->post('/supertext/check', [SettingsController::class, 'check']);
        $routes->get(Reporting::PING_PATH, [PingController::class, 'ping']);
    }

    /** The Commando Center's ping needs no login (GET only). */
    public function onApiCollectPublicRoutes(Event $event): void
    {
        $exact = (array)$event['exact'];
        $exact[] = 'GET ' . $event['api_base'] . Reporting::PING_PATH;
        $event['exact'] = $exact;
    }

    /**
     * After the plugin's settings were saved in Admin2: check the API key and report the
     * versions to Supertext (the settings page shows the check). Never fails the save.
     */
    public function onApiConfigUpdated(Event $event): void
    {
        if (($event['scope'] ?? '') !== 'plugins/supertext-translation') {
            return;
        }
        try {
            $data = $event['data'] ?? null;
            $config = is_array($data) ? $data : (array)$this->config->get('plugins.supertext-translation', []);
            $settings = Settings::fromConfig($config);
            GravReporting::create($this->grav, $settings)->run(Reporting::AFTER_SETTINGS_SAVED, $settings->apiKey);
        } catch (\Throwable) {
            // The center is optional; the settings are saved either way.
        }
    }

    /**
     * The status field on the settings page (supertext_status) shows the API key check; it has no
     * value of its own, so it is never written to the plugin's config file.
     */
    public function onAdminSave(Event $event): void
    {
        $object = $event['object'] ?? null;
        if (!$object instanceof \Grav\Common\Data\Data || $object->get('supertext_status') === null) {
            return;
        }
        $file = $object->file();
        if ($file instanceof \RocketTheme\Toolbox\File\AbstractFile && str_ends_with(str_replace('\\', '/', (string)$file->filename()), 'plugins/supertext-translation.yaml')) {
            $object->undef('supertext_status');
        }
    }

    /** Reporter work queued during the request runs here, after the response was sent. */
    public function onShutdown(): void
    {
        Reporting::flushDeferred();
    }

    public function onApiContextPanels(Event $event): void
    {
        $panels = $event['panels'];
        $panels[] = [
            'id' => 'supertext-translation',
            'plugin' => 'supertext-translation',
            'label' => Messages::forUser($this->grav, $event['user'] ?? null)->text('PANEL_LABEL', [], 'Supertext translation'),
            'icon' => 'languages',
            'contexts' => ['pages'],
            'priority' => 5,
            'width' => 480,
            'authorize' => ['api.pages.write', 'api.pages.read'],
        ];
        $event['panels'] = $panels;
    }
}
