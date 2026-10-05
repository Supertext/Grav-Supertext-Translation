<?php

declare(strict_types=1);

/**
 * Demo setup that runs on every start (idempotent):
 *  - merges demo/user/config/system-overrides.yaml into user/config/system.yaml
 *    (languages, proxy headers), keeping everything else that is there;
 *  - writes the plugin settings only if none exist yet (so changes made in the admin stay).
 *
 * Usage: php configure.php /path/to/grav /path/to/demo
 */

$root = rtrim($argv[1] ?? '/var/www/html', '/');
$demo = rtrim($argv[2] ?? __DIR__, '/');
require $root . '/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$systemFile = $root . '/user/config/system.yaml';
$system = is_file($systemFile) ? (Yaml::parseFile($systemFile) ?: []) : [];
$overrides = Yaml::parseFile($demo . '/user/config/system-overrides.yaml') ?: [];
$merged = array_replace_recursive($system, $overrides);
// Lists are replaced, not merged by index.
$merged['languages']['supported'] = $overrides['languages']['supported'];
if ($merged !== $system) {
    file_put_contents($systemFile, Yaml::dump($merged, 10, 2));
    echo "[demo config] languages: " . implode(', ', $merged['languages']['supported']) . PHP_EOL;
}

$pluginFile = $root . '/user/config/plugins/supertext-translation.yaml';
if (!is_file($pluginFile)) {
    @mkdir(dirname($pluginFile), 0775, true);
    copy($demo . '/user/config/plugins/supertext-translation.yaml', $pluginFile);
    echo "[demo config] wrote the Supertext plugin settings" . PHP_EOL;
}

$siteFile = $root . '/user/config/site.yaml';
$site = is_file($siteFile) ? (Yaml::parseFile($siteFile) ?: []) : [];
if (($site['title'] ?? '') === 'Grav' || $site === []) {
    copy($demo . '/user/config/site.yaml', $siteFile);
    echo "[demo config] wrote the site settings" . PHP_EOL;
}

echo '[demo config] SUPERTEXT_API_KEY is ' . (getenv('SUPERTEXT_API_KEY') ? 'set' : 'NOT set (translations will ask for a key)') . PHP_EOL;
