<?php

declare(strict_types=1);

/**
 * Stand-in for the Supertext file API, used for screenshots and local testing.
 * It answers like https://api.supertext.com/v1/ and returns real German and French
 * translations of the demo's sample content (translations.php), so the guides never
 * show placeholder text. Unknown text is returned unchanged and logged.
 *
 *   php -S 127.0.0.1:8089 scripts/stand-in-api/router.php
 *   then set the plugin's api_url to http://127.0.0.1:8089/v1/
 */

$dictionary = require __DIR__ . '/translations.php';
$store = sys_get_temp_dir() . '/supertext-stand-in';
@mkdir($store, 0777, true);

$method = $_SERVER['REQUEST_METHOD'];
$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function reply(int $status, mixed $data, string $type = 'application/json'): void
{
    http_response_code($status);
    header('Content-Type: ' . $type);
    echo $type === 'application/json' ? json_encode($data) : $data;
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Supertext-Auth-Key \S+$/', $auth)) {
    reply(403, ['message' => 'Missing or malformed Authorization header']);
    return;
}

if ($method === 'GET' && $path === '/v1/features') {
    reply(200, ['features' => ['ai_file_translation']]);
    return;
}

if ($method === 'POST' && $path === '/v1/translate/ai/file') {
    $target = (string)($_POST['target_lang'] ?? '');
    $file = $_FILES['file'] ?? null;
    if ($target === '' || !$file || ($file['type'] ?? '') !== 'text/html') {
        reply(415, ['message' => 'FILETYPE_NOT_ALLOWED']);
        return;
    }
    $id = bin2hex(random_bytes(8));
    file_put_contents("$store/$id.json", json_encode([
        'target' => $target,
        'html' => file_get_contents($file['tmp_name']),
        'created' => microtime(true),
    ]));
    reply(200, ['file_id' => $id]);
    return;
}

if (preg_match('#^/v1/translate/ai/file/([a-f0-9]+)(/status|/translation)?$#', $path, $m)) {
    $job = "$store/{$m[1]}.json";
    if (!is_file($job)) {
        reply(404, ['message' => 'File not found']);
        return;
    }
    $data = json_decode((string)file_get_contents($job), true);
    if ($method === 'DELETE') {
        unlink($job);
        http_response_code(204);
        return;
    }
    if (($m[2] ?? '') === '/status') {
        // Takes a moment, like the real service.
        reply(200, ['status' => microtime(true) - $data['created'] > 1.5 ? 'done' : 'in_progress']);
        return;
    }
    $language = strtolower(substr($data['target'], 0, 2));
    $translations = $dictionary[$language] ?? [];
    $html = preg_replace_callback(
        '#(<(p|div|h[1-6]) data-st-id="\d+">)(.*?)(</\2>)#s',
        static function (array $s) use ($translations): string {
            $inner = $s[3];
            if (!isset($translations[$inner])) {
                error_log('[stand-in] no translation for: ' . $inner);
                return $s[0];
            }
            return $s[1] . $translations[$inner] . $s[4];
        },
        (string)$data['html'],
    );
    reply(200, $html, 'text/html; charset=utf-8');
    return;
}

reply(404, ['message' => 'Unknown route']);
