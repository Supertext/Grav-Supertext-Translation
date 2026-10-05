<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Tests;

use Grav\Plugin\SupertextTranslation\Api\Transport;

/**
 * In-memory stand-in for the Supertext file API. "Translates" by running a
 * callback over the text nodes of each data-st-id element, like the real API
 * which keeps markup and attributes.
 */
final class FakeSupertext implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /** @var array<string, array{html: string, target: string, polls: int}> */
    public array $files = [];

    /** @var list<array{status: int, headers?: array<string, string>, body?: string}> responses served before normal handling */
    public array $queue = [];

    public int $pollsUntilDone = 1;

    /** @var callable(string, string): string text, target language => translated text */
    public $translator;

    /** @var array<string, string> target => final status override (error, limit_exceeded) */
    public array $failFor = [];

    public function __construct(?callable $translator = null)
    {
        $this->translator = $translator ?? static fn(string $text, string $target): string => mb_strtoupper($text);
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        if ($this->queue !== []) {
            $next = array_shift($this->queue);
            return ['status' => $next['status'], 'headers' => $next['headers'] ?? [], 'body' => $next['body'] ?? ''];
        }
        $path = (string)parse_url($url, PHP_URL_PATH);

        if ($method === 'GET' && str_ends_with($path, '/features')) {
            return $this->json(200, ['features' => []]);
        }
        if ($method === 'POST' && str_ends_with($path, '/translate/ai/file')) {
            preg_match('/name="target_lang"\r\n\r\n([^\r]*)/', (string)$body, $t);
            preg_match('/Content-Type: text\/html\r\n\r\n(.*)\r\n--/s', (string)$body, $f);
            $id = 'file-' . (count($this->files) + 1);
            $this->files[$id] = ['html' => $f[1] ?? '', 'target' => $t[1] ?? '', 'polls' => 0];
            return $this->json(200, ['file_id' => $id]);
        }
        if (preg_match('#/translate/ai/file/([^/]+)(/status|/translation)?$#', $path, $m)) {
            $id = rawurldecode($m[1]);
            if (!isset($this->files[$id])) {
                return $this->json(404, ['message' => 'not found']);
            }
            $file = &$this->files[$id];
            if ($method === 'DELETE') {
                unset($this->files[$id]);
                return ['status' => 204, 'headers' => [], 'body' => ''];
            }
            if (($m[2] ?? '') === '/status') {
                $file['polls']++;
                if (isset($this->failFor[$file['target']])) {
                    return $this->json(200, ['status' => $this->failFor[$file['target']]]);
                }
                return $this->json(200, ['status' => $file['polls'] >= $this->pollsUntilDone ? 'done' : 'in_progress']);
            }
            return ['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => $this->translateHtml($file['html'], $file['target'])];
        }
        return $this->json(404, ['message' => 'unknown route']);
    }

    private function translateHtml(string $html, string $target): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//*[@data-st-id]') ?: [] as $element) {
            foreach ($xpath->query('.//text()', $element) ?: [] as $text) {
                if (trim($text->nodeValue ?? '') !== '') {
                    $text->nodeValue = ($this->translator)((string)$text->nodeValue, $target);
                }
            }
        }
        return (string)$dom->saveHTML();
    }

    /** @param array<string, mixed> $data */
    private function json(int $status, array $data): array
    {
        return ['status' => $status, 'headers' => ['content-type' => 'application/json'], 'body' => (string)json_encode($data)];
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_map(static fn(array $r): string => $r['method'] . ' ' . parse_url($r['url'], PHP_URL_PATH), $this->requests);
    }
}
