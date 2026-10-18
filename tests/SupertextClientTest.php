<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Tests;

use Grav\Plugin\SupertextTranslation\Api\SupertextClient;
use Grav\Plugin\SupertextTranslation\Api\SupertextException;
use Grav\Plugin\SupertextTranslation\Settings;
use PHPUnit\Framework\TestCase;
use Supertext\GravTranslation\Sdk\V1\Client;
use Supertext\GravTranslation\Sdk\V1\Protocol;
use Supertext\GravTranslation\Sdk\V1\SupertextException as SdkException;

/**
 * The plugin's layer over the bundled SDK. The protocol itself (auth header, multipart,
 * language codes, retries) is the SDK's and tested there against the shared test vectors;
 * these tests check how the plugin uses it.
 */
final class SupertextClientTest extends TestCase
{
    /** @var list<float> */
    private array $sleeps = [];

    private function client(FakeSupertext $api, string $key = 'secret'): SupertextClient
    {
        $sdk = new Client($key, Protocol::LIVE_BASE_URL, pollInterval: 2.0, timeout: 300.0, transport: $api, sleep: function (float $s): void {
            $this->sleeps[] = $s;
        });

        return new SupertextClient($sdk);
    }

    public function testSubmitsEveryLanguageBeforeWaitingSoTheyRunInParallel(): void
    {
        $api = new FakeSupertext();
        $api->pollsUntilDone = 2;
        $result = $this->client($api)->translateMany([
            'de' => ['html' => '<div data-st-id="0">Hello</div>', 'target' => 'de-CH', 'source' => 'en'],
            'fr' => ['html' => '<div data-st-id="0">Hello</div>', 'target' => 'fr-CH', 'source' => 'en'],
        ]);

        self::assertStringContainsString('HELLO', (string)$result['de']);
        self::assertStringContainsString('HELLO', (string)$result['fr']);
        // Both files are at Supertext before the plugin starts waiting for the first one.
        self::assertSame(['POST /v1/translate/ai/file', 'POST /v1/translate/ai/file', 'GET /v1/translate/ai/file/file-1/status'], array_slice($api->paths(), 0, 3));
        self::assertContains('DELETE /v1/translate/ai/file/file-1', $api->paths());
        self::assertContains('DELETE /v1/translate/ai/file/file-2', $api->paths());
        self::assertSame([], $api->files, 'every file is deleted');
    }

    public function testUsesTheSdkProtocol(): void
    {
        $api = new FakeSupertext();
        $this->client($api, '  Supertext-Auth-Key abc+/=  ')->translateMany([
            'de' => ['html' => '<div data-st-id="0">x</div>', 'target' => 'de-CH', 'source' => 'en', 'politeness' => 'more'],
        ]);
        $post = $api->requests[0];
        self::assertSame('Supertext-Auth-Key abc+/=', $post['headers']['Authorization']);
        // The codes are sent as given: Settings resolves them (LanguageCodesTest), nothing is guessed.
        self::assertStringContainsString("name=\"target_lang\"\r\n\r\nde-CH\r\n", (string)$post['body']);
        self::assertStringContainsString("name=\"source_lang\"\r\n\r\nen\r\n", (string)$post['body']);
        self::assertStringContainsString("name=\"politeness\"\r\n\r\nmore\r\n", (string)$post['body']);
        self::assertStringContainsString("Content-Type: text/html\r\n\r\n", (string)$post['body']);
        self::assertStringStartsWith('supertext-sdk-php', $post['headers']['User-Agent'] ?? '');
    }

    public function testOneFailingLanguageDoesNotStopTheOthers(): void
    {
        $api = new FakeSupertext();
        $api->failFor['fr-FR'] = 'limit_exceeded';
        $result = $this->client($api)->translateMany([
            'de' => ['html' => '<div data-st-id="0">x</div>', 'target' => 'de-CH'],
            'fr' => ['html' => '<div data-st-id="0">x</div>', 'target' => 'fr-FR'],
        ]);
        self::assertIsString($result['de']);
        self::assertInstanceOf(SupertextException::class, $result['fr']);
        self::assertSame('limit_exceeded', $result['fr']->reason);
        self::assertStringContainsString('limit is exceeded', $result['fr']->getMessage());
        self::assertSame([], $api->files, 'failed files are deleted too');
    }

    public function testMissingKeyFailsEveryLanguageWithoutAnyRequest(): void
    {
        $api = new FakeSupertext();
        $result = $this->client($api, '')->translateMany([
            'de' => ['html' => '<div data-st-id="0">x</div>', 'target' => 'de'],
            'fr' => ['html' => '<div data-st-id="0">x</div>', 'target' => 'fr'],
        ]);
        foreach (['de', 'fr'] as $lang) {
            self::assertInstanceOf(SupertextException::class, $result[$lang]);
            self::assertSame('no_api_key', $result[$lang]->reason);
            self::assertStringContainsString('https://www.supertext.com/en/integrations/api', $result[$lang]->getMessage());
        }
        self::assertSame([], $api->requests);
    }

    public function testRefusedKeyIsNotSentForTheOtherLanguages(): void
    {
        $api = new FakeSupertext();
        $api->queue = [['status' => 403, 'body' => '{"message":"forbidden"}']];
        $result = $this->client($api)->translateMany([
            'de' => ['html' => '<div data-st-id="0">x</div>', 'target' => 'de'],
            'fr' => ['html' => '<div data-st-id="0">x</div>', 'target' => 'fr'],
        ]);
        self::assertSame('auth_failed', $result['de']->reason);
        self::assertSame('auth_failed', $result['fr']->reason);
        self::assertSame('', $result['de']->detail, 'nothing from the answer next to a key error');
        self::assertSame(403, $result['de']->status, 'the status marks the key as rejected for the Commando Center');
        self::assertCount(1, $api->requests);
    }

    public function testUnknownLanguageIsAnUnsupportedLanguagePair(): void
    {
        // The live API answers a language it doesn't know at all with HTTP 404 INVALID_LANGUAGE_PAIR.
        $api = new FakeSupertext();
        $api->queue = [['status' => 404, 'headers' => ['content-type' => 'application/json'], 'body' => '{"error_code":"INVALID_LANGUAGE_PAIR","source_lang":"en","target_lang":"xx-YY"}']];
        $result = $this->client($api)->translateMany([
            'xx' => ['html' => '<div data-st-id="0">Hello</div>', 'target' => 'xx-YY', 'source' => 'en'],
        ]);
        self::assertInstanceOf(SupertextException::class, $result['xx']);
        self::assertSame('invalid_language_pair', $result['xx']->reason);
        self::assertSame(['en', 'xx-YY'], $result['xx']->args);
    }

    public function testRateLimitIsRetriedBySdk(): void
    {
        $api = new FakeSupertext();
        $api->queue = [['status' => 429, 'headers' => ['Retry-After' => '3']]];
        $this->client($api)->validateApiKey();
        self::assertCount(2, $api->requests);
        self::assertSame([3.0], $this->sleeps);
    }

    /** @return iterable<string, array{SdkException, string, list<string|int>}> */
    public static function sdkErrors(): iterable
    {
        yield 'language pair' => [new SdkException(SdkException::INVALID_LANGUAGE_PAIR, 400, 'Invalid language pair', 'INVALID_LANGUAGE_PAIR', 'en', 'xx-XX'), 'invalid_language_pair', ['en', 'xx-XX']];
        yield 'unexpected status' => [new SdkException(SdkException::UNEXPECTED_STATUS, 418), 'http_error', [418]];
        yield 'network' => [new SdkException(SdkException::TRANSPORT_ERROR, null, 'Could not resolve host'), 'unreachable', ['Could not resolve host']];
        yield 'quota' => [new SdkException(SdkException::QUOTA_EXCEEDED), 'limit_exceeded', []];
        yield 'timeout' => [new SdkException(SdkException::TIMEOUT), 'timeout', []];
        yield 'too large' => [new SdkException(SdkException::PAYLOAD_TOO_LARGE, 413), 'too_long', []];
        yield 'empty' => [new SdkException(SdkException::INCOMPLETE_RESPONSE), 'empty', []];
        yield 'not mapped target' => [new SdkException(SdkException::UNSUPPORTED_LANGUAGE, targetLang: 'de'), 'unsupported_language', ['de']];
        yield 'not mapped source' => [new SdkException(SdkException::UNSUPPORTED_LANGUAGE, sourceLang: 'xx'), 'unsupported_language', ['xx']];
        yield 'no formal/informal' => [new SdkException(SdkException::UNSUPPORTED_FEATURE, 400, 'FEATURE_ERROR', 'FEATURE_ERROR', feature: 'politeness'), 'unsupported_feature', []];
    }

    /** @param list<string|int> $args */
    #[\PHPUnit\Framework\Attributes\DataProvider('sdkErrors')]
    public function testSdkErrorsBecomeTranslatableReasons(SdkException $sdk, string $reason, array $args): void
    {
        $e = SupertextClient::fromSdk($sdk);
        self::assertSame($reason, $e->reason);
        self::assertSame($args, $e->args);
    }

    public function testEverySdkErrorCodeHasAReason(): void
    {
        $codes = array_filter((new \ReflectionClass(SdkException::class))->getConstants(), 'is_string');
        foreach ($codes as $code) {
            self::assertNotSame('', SupertextClient::fromSdk(new SdkException($code))->reason, $code);
        }
    }

    private const REVIEW_HOST = 'localizationreview-production.up.railway.app';

    /** @return list<string> "host METHOD path" of every request */
    private static function calls(FakeSupertext $api): array
    {
        return array_map(static fn(array $r): string => parse_url($r['url'], PHP_URL_HOST) . ' ' . $r['method'] . ' ' . parse_url($r['url'], PHP_URL_PATH), $api->requests);
    }

    public function testReviewLanguagesGoThroughTheReviewServiceAndTheOthersDirectly(): void
    {
        $api = new FakeSupertext();
        $client = SupertextClient::fromSettings(new Settings(apiKey: 'secret', pollTimeout: 60), 'Grav-Supertext-Translation/1.2.3', $api, static function (): void {});
        self::assertTrue($client->hasReviewService());
        $result = $client->translateMany([
            'de' => ['html' => '<div data-st-id="0">Hello</div>', 'target' => 'de-CH', 'source' => 'en', 'review' => true],
            'fr' => ['html' => '<div data-st-id="0">Hello</div>', 'target' => 'fr-CH', 'source' => 'en'],
        ]);
        self::assertStringContainsString('HELLO', (string)$result['de']);
        self::assertStringContainsString('HELLO', (string)$result['fr']);
        $calls = self::calls($api);
        // All four calls of the review language go to the review service, with the same key and User-Agent.
        foreach (['POST /v1/translate/ai/file', 'GET /v1/translate/ai/file/file-1/status', 'GET /v1/translate/ai/file/file-1/translation', 'DELETE /v1/translate/ai/file/file-1'] as $call) {
            self::assertContains(self::REVIEW_HOST . ' ' . $call, $calls);
        }
        foreach (['POST /v1/translate/ai/file', 'GET /v1/translate/ai/file/file-2/status', 'GET /v1/translate/ai/file/file-2/translation', 'DELETE /v1/translate/ai/file/file-2'] as $call) {
            self::assertContains('api.supertext.com ' . $call, $calls);
        }
        self::assertCount(8, $calls);
        foreach ($api->requests as $request) {
            self::assertSame('Supertext-Auth-Key secret', $request['headers']['Authorization']);
            self::assertStringContainsString('Grav-Supertext-Translation/1.2.3', $request['headers']['User-Agent'] ?? '');
        }
        self::assertSame([], $api->files);
    }

    public function testReviewServiceDefaultsToSupertextsAndIsOnlyCreatedWhenNeeded(): void
    {
        self::assertSame('https://' . self::REVIEW_HOST . '/v1/', SupertextClient::REVIEW_URL);
        self::assertSame(SupertextClient::REVIEW_URL, (new Settings())->reviewUrl);
        self::assertSame(SupertextClient::REVIEW_URL, Settings::fromArray([])->reviewUrl);
        $api = new FakeSupertext();
        SupertextClient::fromSettings(new Settings(apiKey: 'secret'), 'ua', $api, static function (): void {})->translateMany([
            'fr' => ['html' => '<div data-st-id="0">x</div>', 'target' => 'fr-CH', 'review' => false],
        ]);
        self::assertSame([], array_filter(self::calls($api), static fn(string $c): bool => str_starts_with($c, self::REVIEW_HOST)));
    }

    public function testEmptyReviewUrlSendsEverythingDirectly(): void
    {
        foreach ([new Settings(apiKey: 'secret', reviewUrl: ''), new Settings(apiKey: 'secret', reviewUrl: ' https://api.supertext.com/v1 ')] as $settings) {
            $api = new FakeSupertext();
            $client = SupertextClient::fromSettings($settings, 'ua', $api, static function (): void {});
            self::assertFalse($client->hasReviewService(), 'no second client for an empty URL or the API URL');
            $client->translateMany(['de' => ['html' => '<div data-st-id="0">x</div>', 'target' => 'de-CH', 'review' => true]]);
            self::assertCount(4, $api->requests);
            foreach (self::calls($api) as $call) {
                self::assertStringStartsWith('api.supertext.com ', $call);
            }
        }
    }

    public function testOtherReviewUrlFromTheSettings(): void
    {
        $api = new FakeSupertext();
        $client = SupertextClient::fromSettings(new Settings(apiKey: 'secret', reviewUrl: 'http://127.0.0.1:3000/v1/'), 'ua', $api, static function (): void {});
        $client->translateMany(['de' => ['html' => '<div data-st-id="0">x</div>', 'target' => 'de-CH', 'review' => true]]);
        self::assertStringStartsWith('http://127.0.0.1:3000/v1/translate/ai/file', $api->requests[0]['url']);
    }
}
