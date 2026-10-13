<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation;

/**
 * The plugin's version, read from blueprints.yaml (the official version file, which Grav
 * shows in the admin's plugin list). Sent to Supertext in the User-Agent.
 */
final class PluginVersion
{
    private static ?string $version = null;

    public static function get(): string
    {
        if (self::$version === null) {
            $blueprint = @file_get_contents(dirname(__DIR__) . '/blueprints.yaml');
            self::$version = is_string($blueprint) && preg_match('/^version:\s*[\'"]?([^\s\'"]+)/m', $blueprint, $m)
                ? $m[1]
                : 'unknown';
        }

        return self::$version;
    }
}
