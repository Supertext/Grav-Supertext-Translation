<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Tests;

use Grav\Plugin\SupertextTranslation\Api\SupertextClient;
use Grav\Plugin\SupertextTranslation\Api\SupertextException;
use PHPUnit\Framework\TestCase;

final class SupertextClientTest extends TestCase
{
    /** @var list<int> */
    private array $sleeps = [];

    private function client(FakeSupertext $api, string $key = 'secret'): SupertextClient
    {
        return new SupertextClient($key, $api, SupertextClient::DEFAULT_BASE_URL, 2, 300, function (int $ms): void {
            $this->sleeps[] = $ms;
        });
    }

    public function testFullProtocolForSeveralLanguages(): void
    {
        $api = new FakeSupertext();
        $api->pollsUntilDone = 2;
        $result = $this->client($api)->translateMany([
            'de' => ['html' => '<p data-st-id="0">Hello</p>', 'target' => 'de-CH', 'source' => 'en-GB'],
            'fr' => ['html' => '<p data-st-id="0">Hello</p>', 'target' => 'fr-CH', 'source' => 'en'],
        ]);

        self::assertStringContainsString('HELLO', (string)$result['de']);
        self::assertStringContainsString('HELLO', (string)$result['fr']);
        // Both submitted before polling starts; every file is deleted afterwards.
        self::assertSame(['POST /v1/translate/ai/file', 'POST /v1/translate/ai/file'], array_slice($api->paths(), 0, 2));
        self::assertContains('DELETE /v1/translate/ai/file/file-1', $api->paths());
        self::assertContains('DELETE /v1/translate/ai/file/file-2', $api->paths());
        self::assertSame([], $api->files);
        self::assertSame([2000], $this->sleeps);

        $post = $api->requests[0];
        self::assertStringContainsString("name=\"source_lang\"\r\n\r\nen\r\n", (string)$post['body']);
        self::assertStringContainsString("name=\"target_lang\"\r\n\r\nde-CH\r\n", (string)$post['body']);
        self::assertStringContainsString("Content-Type: text/html\r\n\r\n", (string)$post['body']);
    }

    public function testAuthorizationHeaderHasExactlyOnePrefix(): void
    {
        $api = new FakeSupertext();
        $this->client($api, '  Supertext-Auth-Key abc+/=  ')->validateApiKey();
        self::assertSame('Supertext-Auth-Key abc+/=', $api->requests[0]['headers']['Authorization']);
        self::assertArrayNotHasKey('Authentication', $api->requests[0]['headers']);
    }

    public function testRateLimitIsRetriedWithRetryAfterThenBackoff(): void
    {
        $api = new FakeSupertext();
        $api->queue = [
            ['status' => 429, 'headers' => ['retry-after' => '3']],
            ['status' => 429],
        ];
        $this->client($api)->validateApiKey();
        self::assertCount(3, $api->requests);
        self::assertSame(3000, $this->sleeps[0]);
        self::assertGreaterThanOrEqual(2000, $this->sleeps[1]);
        self::assertLessThanOrEqual(2250, $this->sleeps[1]);
    }

    public function testRateLimitGivesUpAfterFourRetries(): void
    {
        $api = new FakeSupertext();
        $api->queue = array_fill(0, 5, ['status' => 429, 'body' => '{"code":"RATE_LIMIT_EXCEEDED"}']);
        $this->expectException(SupertextException::class);
        $this->expectExceptionMessage('too many requests');
        try {
            $this->client($api)->validateApiKey();
        } finally {
            self::assertCount(5, $api->requests);
        }
    }

    public function testBadKeyHasAClearMessage(): void
    {
        $api = new FakeSupertext();
        $api->queue = [['status' => 403, 'body' => '{"message":"forbidden"}']];
        $this->expectExceptionMessage('Supertext refused the API key');
        $this->client($api)->validateApiKey();
    }

    public function testMissingKeyFailsBeforeAnyRequest(): void
    {
        $api = new FakeSupertext();
        $result = $this->client($api, '')->translateMany(['de' => ['html' => '<p data-st-id="0">x</p>', 'target' => 'de']]);
        self::assertInstanceOf(SupertextException::class, $result['de']);
        self::assertStringContainsString('No Supertext API key', $result['de']->getMessage());
        self::assertSame([], $api->requests);
    }

    public function testOneFailingLanguageDoesNotStopTheOthers(): void
    {
        $api = new FakeSupertext();
        $api->failFor['fr'] = 'limit_exceeded';
        $result = $this->client($api)->translateMany([
            'de' => ['html' => '<p data-st-id="0">x</p>', 'target' => 'de'],
            'fr' => ['html' => '<p data-st-id="0">x</p>', 'target' => 'fr'],
        ]);
        self::assertIsString($result['de']);
        self::assertInstanceOf(SupertextException::class, $result['fr']);
        self::assertStringContainsString('limit is exceeded', $result['fr']->getMessage());
        self::assertSame([], $api->files, 'failed files are deleted too');
    }
}
