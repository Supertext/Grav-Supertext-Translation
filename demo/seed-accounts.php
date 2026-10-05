<?php

declare(strict_types=1);

/**
 * Creates the demo accounts from environment variables, on every start.
 *
 *   DEMO_ADMIN_EMAIL / DEMO_ADMIN_PASSWORD    full administrator (api.super)
 *   DEMO_EDITOR_EMAIL / DEMO_EDITOR_PASSWORD  editor: may edit and translate pages in every language
 *
 * Existing accounts are never changed. A password that breaks Grav's password
 * rule (system.pwd_regex) skips that account with a warning; the demo still starts.
 * Passwords are never printed.
 *
 * Usage: php seed-accounts.php /path/to/grav
 */

$root = rtrim($argv[1] ?? '/var/www/html', '/');
require $root . '/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

function out(string $message): void
{
    fwrite(STDOUT, '[demo accounts] ' . $message . PHP_EOL);
}

function config(string $root, string $key, mixed $default): mixed
{
    $value = $default;
    foreach ([$root . '/system/config/system.yaml', $root . '/user/config/system.yaml'] as $file) {
        if (is_file($file)) {
            $data = Yaml::parseFile($file);
            if (is_array($data) && array_key_exists($key, $data)) {
                $value = $data[$key];
            }
        }
    }
    return $value;
}

$accountsDir = $root . '/user/accounts';
if (!is_dir($accountsDir)) {
    mkdir($accountsDir, 0775, true);
}

// Emails and usernames that already exist.
$existingEmails = [];
foreach (glob($accountsDir . '/*.yaml') ?: [] as $file) {
    $data = Yaml::parseFile($file);
    if (is_array($data) && isset($data['email'])) {
        $existingEmails[strtolower((string)$data['email'])] = basename($file, '.yaml');
    }
}

$pwdRegex = (string)config($root, 'pwd_regex', '(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}');
$usernameRegex = (string)config($root, 'username_regex', '^[a-z0-9_-]{3,16}$');

$accounts = [
    'DEMO_ADMIN' => [
        'fullname' => 'Demo Administrator',
        'title' => 'Administrator',
        'access' => [
            'site' => ['login' => true],
            'api' => ['super' => true, 'access' => true],
        ],
    ],
    'DEMO_EDITOR' => [
        'fullname' => 'Demo Editor',
        'title' => 'Editor',
        // Editors: edit pages and their media in every language, translate with Supertext.
        'access' => [
            'site' => ['login' => true],
            'api' => [
                'access' => true,
                'pages' => ['read' => true, 'write' => true],
                'media' => ['read' => true, 'write' => true],
                'system' => ['read' => true],
            ],
        ],
    ],
];

foreach ($accounts as $prefix => $profile) {
    $email = trim((string)getenv($prefix . '_EMAIL'));
    $password = (string)getenv($prefix . '_PASSWORD');
    if ($email === '' || $password === '') {
        out("{$prefix}_EMAIL / {$prefix}_PASSWORD not set: skipped.");
        continue;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        out("WARNING: {$prefix}_EMAIL is not a valid email address: account skipped.");
        continue;
    }
    if (isset($existingEmails[strtolower($email)])) {
        out("{$prefix}: account '{$existingEmails[strtolower($email)]}' already exists, left unchanged.");
        continue;
    }
    if (!preg_match('#' . $pwdRegex . '#', $password)) {
        out("WARNING: {$prefix}_PASSWORD does not meet Grav's password rule (at least 8 characters with a number, an upper- and a lower-case letter): account skipped.");
        continue;
    }

    // Username from the part before the @, made to fit Grav's username rule.
    $base = strtolower((string)preg_replace('/[^a-z0-9_-]+/i', '-', strstr($email, '@', true) ?: 'demo'));
    $base = trim(substr($base, 0, 14), '-') ?: 'demo';
    if (strlen($base) < 3) {
        $base = str_pad($base, 3, '0');
    }
    $username = $base;
    for ($i = 2; is_file($accountsDir . '/' . $username . '.yaml'); $i++) {
        $username = substr($base, 0, 14) . $i;
    }
    if (!preg_match('#' . $usernameRegex . '#', $username)) {
        out("WARNING: could not derive a valid username from {$prefix}_EMAIL: account skipped.");
        continue;
    }

    $account = [
        'email' => $email,
        'fullname' => $profile['fullname'],
        'title' => $profile['title'],
        'state' => 'enabled',
        'language' => 'en',
        'access' => $profile['access'],
        'hashed_password' => password_hash($password, PASSWORD_DEFAULT),
        'created' => time(),
        'modified' => time(),
    ];
    file_put_contents($accountsDir . '/' . $username . '.yaml', Yaml::dump($account, 10, 2));
    $existingEmails[strtolower($email)] = $username;
    out("{$prefix}: created account '{$username}' from {$prefix}_EMAIL.");
}
