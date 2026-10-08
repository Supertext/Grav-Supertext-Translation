<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\SupertextTranslation\Controller\TranslationController;
use Grav\Plugin\SupertextTranslation\Messages;
use RocketTheme\Toolbox\Event\Event;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Grav\\Plugin\\SupertextTranslation\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

/**
 * Supertext Translation for Grav 2.
 *
 * Adds a "Supertext" panel to the page editor of Admin2 (the toolbar button
 * with the languages icon) and the API routes the panel calls.
 */
class SupertextTranslationPlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            'onApiContextPanels' => ['onApiContextPanels', 0],
        ];
    }

    public function onApiRegisterRoutes(Event $event): void
    {
        $routes = $event['routes'];
        $routes->get('/supertext/status', [TranslationController::class, 'status']);
        $routes->post('/supertext/translate', [TranslationController::class, 'translate']);
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
