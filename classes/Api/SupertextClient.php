<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Api;

/**
 * Supertext AI file translation API v1 (https://api.supertext.com/v1/).
 *
 * Same protocol as the Supertext WordPress, TYPO3 and Payload plugins:
 * POST the HTML file → poll its status → GET the translation → DELETE the file.
 *
 * Several target languages are submitted one after the other and then polled
 * together, so translating into three languages takes about as long as one.
 */
final class SupertextClient
{
    public const DEFAULT_BASE_URL = 'https://api.supertext.com/v1/';
    private const RATE_LIMIT_RETRIES = 4;
    public const MAX_DOCUMENT_CHARACTERS = 900000;

    /** @var callable(int): void sleeps for the given number of milliseconds */
    private $sleep;

    public function __construct(
        private readonly string $apiKey,
        private readonly Transport $transport = new CurlTransport(),
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly int $pollInterval = 2,
        private readonly int $pollTimeout = 300,
        ?callable $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /** Removes the "Supertext-Auth-Key " prefix Supertext shows the key with. */
    public static function normalizeKey(string $key): string
    {
        return trim((string)preg_replace('/^\s*Supertext-Auth-Key\s+/i', '', trim($key)));
    }

    public function hasApiKey(): bool
    {
        return self::normalizeKey($this->apiKey) !== '';
    }

    /** Cost-free check of the API key (GET /features). */
    public function validateApiKey(): void
    {
        $this->request('GET', 'features');
    }

    /**
     * Translates one document into several languages.
     *
     * @param array<string, array{html: string, target: string, source?: string, politeness?: string}> $jobs keyed by your own id
     * @return array<string, string|SupertextException> translated HTML, or the error for that job
     */
    public function translateMany(array $jobs): array
    {
        $results = [];
        $pending = [];
        foreach ($jobs as $key => $job) {
            if (mb_strlen($job['html']) > self::MAX_DOCUMENT_CHARACTERS) {
                $results[$key] = SupertextException::because('too_long', 'The page is too long to translate in one go.');
                continue;
            }
            try {
                $pending[$key] = $this->submit($job['html'], $job['target'], $job['source'] ?? '', $job['politeness'] ?? 'default');
            } catch (SupertextException $e) {
                $results[$key] = $e;
            }
        }

        $deadline = time() + $this->pollTimeout;
        while ($pending !== []) {
            foreach ($pending as $key => $fileId) {
                try {
                    $status = (string)($this->json($this->request('GET', 'translate/ai/file/' . rawurlencode($fileId) . '/status'))['status'] ?? '');
                    $error = match ($status) {
                        'error' => ['translation_failed', 'Supertext could not translate the page.'],
                        'limit_exceeded' => ['limit_exceeded', 'Your Supertext translation limit is exceeded.'],
                        'deleted' => ['deleted', 'The file was deleted at Supertext before the translation could be downloaded.'],
                        default => null,
                    };
                    if ($error !== null) {
                        throw SupertextException::because($error[0], $error[1]);
                    }
                    if ($status !== 'done') {
                        continue;
                    }
                    $body = $this->request('GET', 'translate/ai/file/' . rawurlencode($fileId) . '/translation')['body'];
                    if (trim($body) === '') {
                        throw SupertextException::because('empty', 'Supertext returned an empty translation.');
                    }
                    $results[$key] = $body;
                } catch (SupertextException $e) {
                    $results[$key] = $e;
                }
                unset($pending[$key]);
                $this->deleteQuietly($fileId);
            }
            if ($pending === []) {
                break;
            }
            if (time() >= $deadline) {
                foreach ($pending as $key => $fileId) {
                    $results[$key] = SupertextException::because('timeout', 'Supertext took too long to answer. Please try again.');
                    $this->deleteQuietly($fileId);
                }
                break;
            }
            ($this->sleep)($this->pollInterval * 1000);
        }

        $ordered = [];
        foreach (array_keys($jobs) as $key) {
            $ordered[$key] = $results[$key] ?? SupertextException::because('no_result', 'No translation was returned.');
        }
        return $ordered;
    }

    private function submit(string $html, string $target, string $source, string $politeness): string
    {
        $fields = ['target_lang' => $target];
        if ($source !== '') {
            // Supertext expects the source as a primary subtag ("en", not "en-GB").
            $fields['source_lang'] = strtolower((string)strtok($source, '-_'));
        }
        if (in_array($politeness, ['more', 'less'], true)) {
            $fields['politeness'] = $politeness;
        }

        $boundary = '----SupertextGrav' . bin2hex(random_bytes(12));
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }
        // The file part's Content-Type must be exactly "text/html" (no charset), else 415.
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"page.html\"\r\n"
            . "Content-Type: text/html\r\n\r\n{$html}\r\n--{$boundary}--\r\n";

        $data = $this->json($this->request('POST', 'translate/ai/file', $body, 'multipart/form-data; boundary=' . $boundary));
        $fileId = (string)($data['file_id'] ?? '');
        if ($fileId === '') {
            throw SupertextException::because('no_file_id', 'Supertext did not accept the page (no file id returned).');
        }
        return $fileId;
    }

    private function deleteQuietly(string $fileId): void
    {
        try {
            $this->request('DELETE', 'translate/ai/file/' . rawurlencode($fileId));
        } catch (\Throwable) {
            // Files expire at Supertext after 24 hours anyway.
        }
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function request(string $method, string $path, ?string $body = null, ?string $contentType = null): array
    {
        $key = self::normalizeKey($this->apiKey);
        if ($key === '') {
            throw SupertextException::because('no_api_key', 'No Supertext API key is configured. An administrator can add it in the plugin settings. No Supertext account yet? Create one at https://www.supertext.com/person/en/account/signin. Generate your API key at https://www.supertext.com/en/integrations/api (requires the Admin role).');
        }
        $headers = [
            // Exactly one prefix, header name "Authorization" (anything else is refused).
            'Authorization' => 'Supertext-Auth-Key ' . $key,
            'Accept' => 'application/json',
        ];
        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');

        // The API limits requests per second per key: retry a 429 up to 4 times.
        for ($attempt = 0; ; $attempt++) {
            $response = $this->transport->send($method, $url, $headers, $body, $body !== null ? 120 : 30);
            if ($response['status'] !== 429 || $attempt >= self::RATE_LIMIT_RETRIES) {
                break;
            }
            $retryAfter = $response['headers']['retry-after'] ?? '';
            $delay = is_numeric($retryAfter)
                ? min(30000, (int)((float)$retryAfter * 1000))
                : 1000 * 2 ** $attempt + random_int(0, 250);
            ($this->sleep)($delay);
        }

        $status = $response['status'];
        if ($status >= 200 && $status < 300) {
            return $response;
        }
        [$reason, $message] = match (true) {
            $status === 401, $status === 403 => ['auth_failed', 'Supertext refused the API key. Please check it in the plugin settings. No Supertext account yet? Create one at https://www.supertext.com/person/en/account/signin. Generate your API key at https://www.supertext.com/en/integrations/api (requires the Admin role).'],
            $status === 404 => ['not_found', 'Supertext could not find the requested file.'],
            $status === 413 => ['too_long', 'The page is too long to translate in one go.'],
            $status === 429 => ['rate_limited', 'Supertext is busy (too many requests). Please try again in a moment.'],
            $status >= 500 => ['unavailable', 'The Supertext service is currently unavailable. Please try again later.'],
            default => ['http_error', 'Supertext answered with HTTP %d.'],
        };
        $detail = $this->json($response)['message'] ?? $this->json($response)['detail'] ?? '';
        $detail = is_string($detail) && !in_array($status, [401, 403], true) ? mb_substr(strip_tags($detail), 0, 200) : '';
        throw SupertextException::because($reason, $message, $reason === 'http_error' ? [$status] : [], $detail);
    }

    /**
     * @param array{body: string} $response
     * @return array<string, mixed>
     */
    private function json(array $response): array
    {
        $data = json_decode($response['body'], true);
        return is_array($data) ? $data : [];
    }
}
