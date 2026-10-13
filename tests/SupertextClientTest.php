<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Tests;

use Grav\Plugin\SupertextTranslation\Api\SupertextClient;
use Grav\Plugin\SupertextTranslation\Api\SupertextException;
use PHPUnit\Framework\TestCase;
use Supertext\GravTranslation\Sdk\Client;
use Supertext\GravTranslation\Sdk\Supertext;
use Supertext\GravTranslation\Sdk\SupertextException as SdkException;

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
        $sdk = new Client($key, Supertext::LIVE_BASE_URL, pollInterval: 2.0, timeout: 300.0, transport: $api, sleep: function (float $s): void {
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
            'de' => ['html' => '<div data-st-id="0">x</div>', 'target' => 'de', 'source' => 'en-GB', 'politeness' => 'more'],
        ]);
        $post = $api->requests[0];
        self::assertSame('Supertext-Auth-Key abc+/=', $post['headers']['Authorization']);
        self::assertStringContainsString("name=\"target_lang\"\r\n\r\nde-DE\r\n", (string)$post['body'], 'bare target gets a region');
        self::assertStringContainsString("name=\"source_lang\"\r\n\r\nen\r\n", (string)$post['body'], 'source sent bare');
        self::assertStringContainsString("name=\"politeness\"\r\n\r\nmore\r\n", (string)$post['body']);
        self::assertStringContainsString("Content-Type: text/html\r\n\r\n", (string)$post['body']);
        self::assertStringStartsWith('supertext-sdk-php', $post['headers']['User-Agent'] ?? '');
    }

    public function testOneFailingLanguageDoesNotStopTheOthers(): void
    {
        $api = new FakeSupertext();
        $api->failFor['fr-FR'] = 'limit_exceeded';
        $result = $this->client($api)->translateMany([
            'de' => ['html' => '<div data-st-id="0">x</div>', 'target' => 'de'],
            'fr' => ['html' => '<div data-st-id="0">x</div>', 'target' => 'fr'],
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
        self::assertCount(1, $api->requests);
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
}
