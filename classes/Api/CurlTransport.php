<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Api;

final class CurlTransport implements Transport
{
    public function send(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array
    {
        if (!function_exists('curl_init')) {
            throw SupertextException::because('no_curl', 'The PHP curl extension is required to reach Supertext.');
        }
        $responseHeaders = [];
        $handle = curl_init($url);
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $result = curl_exec($handle);
        if ($result === false) {
            $error = curl_error($handle);
            throw SupertextException::because('unreachable', 'Could not reach Supertext: %s', [$error]);
        }
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string)$result];
    }
}
