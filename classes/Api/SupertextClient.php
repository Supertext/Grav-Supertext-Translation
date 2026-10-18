<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Api;

use Grav\Plugin\SupertextTranslation\Settings;
use Supertext\GravTranslation\Sdk\Http\HttpTransport;
use Supertext\GravTranslation\Sdk\V1\Client;
use Supertext\GravTranslation\Sdk\V1\Protocol;
use Supertext\GravTranslation\Sdk\V1\SupertextException as SdkException;

/**
 * The plugin's use of the bundled Supertext SDK (lib/supertext-sdk).
 *
 * The SDK does the protocol: auth header, multipart upload, 429 retries,
 * polling and deleting the file. This class only adds what Grav needs on top:
 *
 * - Several languages at once: every target is submitted first, then each one is awaited
 *   and downloaded. Supertext works on all files in parallel, so three languages take
 *   about as long as one. One failing language doesn't stop the others.
 * - Errors as the plugin's SupertextException with a reason, so the admin shows them in
 *   the user's language (languages.yaml → PLUGIN_SUPERTEXT_TRANSLATION.ERRORS).
 * - Translation review: jobs for languages with *Translation review* on go to Supertext's
 *   review service (a second SDK client, same key and settings, base URL review_url); each
 *   job is collected and deleted with the client that submitted it.
 */
final class SupertextClient
{
    /** SDK error code => plugin reason (languages.yaml ERRORS.<REASON>) and English message. */
    private const REASONS = [
        SdkException::MISSING_API_KEY => ['no_api_key', 'No Supertext API key is configured. An administrator can add it in the plugin settings. No Supertext account yet? Create one at https://www.supertext.com/person/en/account/signin. Generate your API key at https://www.supertext.com/en/integrations/api (requires the Admin role).'],
        SdkException::AUTHENTICATION_FAILURE => ['auth_failed', 'Supertext refused the API key. Please check it in the plugin settings. No Supertext account yet? Create one at https://www.supertext.com/person/en/account/signin. Generate your API key at https://www.supertext.com/en/integrations/api (requires the Admin role).'],
        SdkException::INVALID_LANGUAGE_PAIR => ['invalid_language_pair', 'Supertext does not translate from %s into %s. Check the Supertext language codes in the plugin settings.'],
        SdkException::UNSUPPORTED_LANGUAGE => ['unsupported_language', 'The language %s has no Supertext language yet. Ask your administrator to map it under Plugins → Supertext Translation → Supertext language codes.'],
        SdkException::UNSUPPORTED_FEATURE => ['unsupported_feature', 'Supertext offers no formal or informal tone for this language. Set the form of address to "Supertext default" in the plugin settings.'],
        SdkException::NOT_FOUND => ['not_found', 'Supertext could not find the requested file.'],
        SdkException::PAYLOAD_TOO_LARGE => ['too_long', 'The page is too long to translate in one go.'],
        SdkException::TOO_MANY_REQUESTS => ['rate_limited', 'Supertext is busy (too many requests). Please try again in a moment.'],
        SdkException::SERVICE_UNAVAILABLE => ['unavailable', 'The Supertext service is currently unavailable. Please try again later.'],
        SdkException::UNEXPECTED_STATUS => ['http_error', 'Supertext answered with HTTP %d.'],
        SdkException::TRANSPORT_ERROR => ['unreachable', 'Could not reach Supertext: %s'],
        SdkException::NO_FILE_ID => ['no_file_id', 'Supertext did not accept the page (no file id returned).'],
        SdkException::TRANSLATION_ERROR => ['translation_failed', 'Supertext could not translate the page.'],
        SdkException::QUOTA_EXCEEDED => ['limit_exceeded', 'Your Supertext translation limit is exceeded.'],
        SdkException::FILE_DELETED => ['deleted', 'The file was deleted at Supertext before the translation could be downloaded.'],
        SdkException::TIMEOUT => ['timeout', 'Supertext took too long to answer. Please try again.'],
        SdkException::INCOMPLETE_RESPONSE => ['empty', 'Supertext returned an empty translation.'],
    ];

    /**
     * Supertext's review service (translation proxy of Supertext Localization Review). It speaks
     * the same API and forwards to the live Supertext API, keeping every translation so reviewers
     * can correct them on the website. Used for languages with *Translation review* on.
     */
    public const REVIEW_URL = 'https://localizationreview-production.up.railway.app/v1/';

    private ?Client $reviewClient = null;

    /**
     * @param \Closure(): Client|null $makeReviewClient builds the client for the review service (null: none)
     */
    public function __construct(
        private readonly Client $client,
        private readonly ?\Closure $makeReviewClient = null,
    ) {
    }

    /**
     * The client for the settings: the API at api_url and, unless review_url is empty or the
     * same URL, the review service with the same key, timeouts, transport and User-Agent.
     *
     * @param callable(float): void|null $sleep (tests)
     * @throws \LogicException without ext-curl and without a transport (the SDK's built-in transport needs it)
     */
    public static function fromSettings(Settings $settings, string $userAgent, ?HttpTransport $transport = null, ?callable $sleep = null): self
    {
        $make = static fn(string $baseUrl): Client => new Client(
            $settings->apiKey,
            baseUrl: $baseUrl,
            pollInterval: (float)$settings->pollInterval,
            timeout: (float)$settings->pollTimeout,
            userAgent: $userAgent,
            transport: $transport,
            sleep: $sleep,
        );
        $client = $make($settings->apiUrl);
        $reviewUrl = trim($settings->reviewUrl);
        $review = $reviewUrl === '' || rtrim($reviewUrl, '/') === rtrim(trim($settings->apiUrl), '/')
            ? null
            : static fn(): Client => $make($reviewUrl);

        return new self($client, $review);
    }

    /** Whether review jobs go to a separate review service (false: everything goes directly). */
    public function hasReviewService(): bool
    {
        return $this->makeReviewClient !== null;
    }

    /** The SDK client for a job: the review service when its language has review on, else the API. */
    private function clientFor(bool $review): Client
    {
        if (!$review || $this->makeReviewClient === null) {
            return $this->client;
        }

        return $this->reviewClient ??= ($this->makeReviewClient)();
    }

    /** Cost-free check of the API key. */
    public function validateApiKey(): void
    {
        try {
            $this->client->validateApiKey();
        } catch (SdkException $e) {
            throw self::fromSdk($e);
        }
    }

    /**
     * Translates documents, typically one page into several languages.
     *
     * @param array<string, array{html: string, target: string, source?: string, politeness?: string, review?: bool}> $jobs keyed by your own id;
     *   review: through Supertext's review service
     * @return array<string, string|SupertextException> translated HTML, or the error for that job, in the order of $jobs
     */
    public function translateMany(array $jobs): array
    {
        $results = [];
        $files = [];
        $clients = []; // the client that submitted each file collects and deletes it
        $stop = null; // an error that is the same for every language (no key, key refused)
        try {
            foreach ($jobs as $key => $job) {
                if ($stop !== null) {
                    $results[$key] = $stop;
                    continue;
                }
                if (mb_strlen($job['html']) > Protocol::DEFAULT_MAX_CHARACTERS) {
                    $results[$key] = self::reason(SdkException::PAYLOAD_TOO_LARGE);
                    continue;
                }
                try {
                    $clients[$key] = $this->clientFor((bool)($job['review'] ?? false));
                    $files[$key] = $clients[$key]->submit($job['html'], $job['target'], $job['source'] ?? '', $job['politeness'] ?? 'default');
                } catch (SdkException $e) {
                    $results[$key] = self::fromSdk($e);
                    if ($e->errorCode === SdkException::MISSING_API_KEY || $e->errorCode === SdkException::AUTHENTICATION_FAILURE) {
                        $stop = $results[$key]; // don't send the other languages
                    }
                }
            }
            foreach ($files as $key => $fileId) {
                try {
                    $clients[$key]->waitUntilDone($fileId);
                    $results[$key] = $clients[$key]->download($fileId);
                } catch (SdkException $e) {
                    $results[$key] = self::fromSdk($e);
                } finally {
                    $clients[$key]->delete($fileId);
                    unset($files[$key]);
                }
            }
        } finally {
            foreach ($files as $key => $fileId) {
                $clients[$key]->delete($fileId); // never throws
            }
        }

        $ordered = [];
        foreach (array_keys($jobs) as $key) {
            $ordered[$key] = $results[$key] ?? SupertextException::because('no_result', 'No translation was returned.');
        }

        return $ordered;
    }

    /** The plugin's error for an SDK error, with a reason the admin can translate. */
    public static function fromSdk(SdkException $e): SupertextException
    {
        [$reason, $message] = self::REASONS[$e->errorCode] ?? ['http_error', 'Supertext answered with HTTP %d.'];
        $args = match ($e->errorCode) {
            SdkException::INVALID_LANGUAGE_PAIR => [$e->sourceLang ?? '?', $e->targetLang ?? '?'],
            SdkException::UNSUPPORTED_LANGUAGE => [$e->targetLang ?? $e->sourceLang ?? '?'],
            SdkException::UNEXPECTED_STATUS => [$e->status ?? 0],
            SdkException::TRANSPORT_ERROR => [$e->detail ?? ''],
            default => [],
        };
        // The answer's own text helps with support, except where it is in the message already or about the key.
        $detail = in_array($e->errorCode, [SdkException::TRANSPORT_ERROR, SdkException::AUTHENTICATION_FAILURE, SdkException::MISSING_API_KEY, SdkException::UNSUPPORTED_LANGUAGE], true)
            ? ''
            : trim((string)$e->detail);

        return SupertextException::because($reason, $message, $args, $detail, $e->status);
    }

    private static function reason(string $sdkCode): SupertextException
    {
        return self::fromSdk(new SdkException($sdkCode));
    }
}
