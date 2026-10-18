<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Tests;

use Grav\Plugin\SupertextTranslation\Api\SupertextClient;
use Grav\Plugin\SupertextTranslation\Api\SupertextException;
use Grav\Plugin\SupertextTranslation\PageFile;
use Grav\Plugin\SupertextTranslation\PageTranslator;
use Grav\Plugin\SupertextTranslation\Settings;
use PHPUnit\Framework\TestCase;
use Supertext\GravTranslation\Sdk\V1\Client;
use Supertext\GravTranslation\Sdk\V1\Protocol;

final class PageTranslatorTest extends TestCase
{
    private string $folder;
    private FakeSupertext $api;
    private PageTranslator $translator;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/st-grav-' . bin2hex(random_bytes(4));
        mkdir($this->folder);
        file_put_contents($this->folder . '/default.en.md', <<<'MD'
            ---
            title: 'Our **services**'
            menu: Services
            metadata:
                description: 'What we offer'
            taxonomy:
                category: [blog]
            ---

            # We translate

            Supertext translates **your website** into [many languages](https://supertext.com).

            - Fast
            - Accurate

            MD);
        $this->api = new FakeSupertext();
        $this->translator = $this->makeTranslator(new Settings(apiKey: 'k', languageCodes: self::MAPPING));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->folder . '/*') ?: []);
        rmdir($this->folder);
    }

    /** The site's languages as the administrator mapped them (bare de/fr have no Supertext code of their own). */
    private const MAPPING = ['de' => 'de-CH', 'fr' => 'fr-FR'];

    private function makeTranslator(Settings $settings): PageTranslator
    {
        $sdk = new Client('k', Protocol::LIVE_BASE_URL, pollInterval: 1.0, timeout: 60.0, transport: $this->api, sleep: static function (): void {});
        $client = new SupertextClient($sdk);
        return new PageTranslator($client, $settings, static fn(): string => '2026-10-05T20:00:00Z');
    }

    private function translate(array $languages = ['de', 'fr'], bool $overwrite = false): array
    {
        return $this->translator->translate($this->folder, 'default', 'en', $languages, $overwrite);
    }

    public function testCreatesUnpublishedTranslationsWithTranslatedFields(): void
    {
        $result = $this->translate();
        self::assertSame('created', $result['de']['result']);
        self::assertSame('created', $result['fr']['result']);

        $de = PageFile::read($this->folder . '/default.de.md');
        self::assertSame('OUR **SERVICES**', $de->header['title'], 'front matter is plain text: no formatting added or lost');
        self::assertSame('SERVICES', $de->header['menu']);
        self::assertSame('WHAT WE OFFER', $de->header['metadata']['description']);
        self::assertSame(['category' => ['blog']], $de->header['taxonomy']);
        self::assertFalse($de->header['published']);
        self::assertSame('de-CH', $de->header['supertext']['language']);
        self::assertSame("# WE TRANSLATE\n\nSUPERTEXT TRANSLATES **YOUR WEBSITE** INTO [MANY LANGUAGES](https://supertext.com).\n\n- FAST\n- ACCURATE\n", $de->body);

        // The source is never touched.
        self::assertStringNotContainsString('supertext:', (string)file_get_contents($this->folder . '/default.en.md'));
        // The mapped codes: de-CH for German, fr-FR for French.
        self::assertStringContainsString("name=\"target_lang\"\r\n\r\nde-CH", (string)$this->api->requests[0]['body']);
        self::assertStringContainsString("name=\"target_lang\"\r\n\r\nfr-FR\r\n", (string)$this->api->requests[1]['body']);
    }

    public function testStates(): void
    {
        $status = $this->translator->status($this->folder, 'default', 'en', ['en', 'de', 'fr']);
        self::assertSame(['de', 'fr'], array_keys($status['languages']));
        self::assertSame('missing', $status['languages']['de']['state']);
        self::assertSame('default.en.md', $status['source']['file']);

        $this->translate(['de']);
        self::assertSame('current', $this->state('de'));

        // Publishing the translation is not an edit.
        $this->edit('de', fn(PageFile $p) => $p->header['published'] = true);
        self::assertSame('current', $this->state('de'));

        // Source changes → outdated.
        file_put_contents($this->folder . '/default.en.md', str_replace('Fast', 'Very fast', (string)file_get_contents($this->folder . '/default.en.md')));
        self::assertSame('outdated', $this->state('de'));

        // Editing the translated text → edited.
        $this->edit('de', fn(PageFile $p) => $p->body .= "\nExtra Satz.\n");
        self::assertSame('edited', $this->state('de'));

        // A file not made by Supertext → manual.
        file_put_contents($this->folder . '/default.fr.md', "---\ntitle: Nos services\n---\n\nBonjour\n");
        self::assertSame('manual', $this->state('fr'));
    }

    public function testUntouchedTranslationIsUpdatedAndKeepsItsPublishedState(): void
    {
        $this->translate(['de']);
        $this->edit('de', fn(PageFile $p) => $p->header['published'] = true);
        file_put_contents($this->folder . '/default.en.md', str_replace('Fast', 'Quick', (string)file_get_contents($this->folder . '/default.en.md')));

        $result = $this->translate(['de']);
        self::assertSame('updated', $result['de']['result']);
        $de = PageFile::read($this->folder . '/default.de.md');
        self::assertTrue($de->header['published']);
        self::assertStringContainsString('- QUICK', $de->body);
        self::assertSame('current', $this->state('de'));
    }

    public function testEditedAndManualTranslationsNeedConfirmation(): void
    {
        $this->translate(['de']);
        $this->edit('de', fn(PageFile $p) => $p->header['title'] = 'Unsere Dienstleistungen');
        file_put_contents($this->folder . '/default.fr.md', "---\ntitle: Nos services\n---\n\nBonjour\n");
        $requestsBefore = count($this->api->requests);

        $result = $this->translate(['de', 'fr']);
        self::assertSame('skipped', $result['de']['result']);
        self::assertSame('edited', $result['de']['state']);
        self::assertSame('skipped', $result['fr']['result']);
        self::assertSame('manual', $result['fr']['state']);
        self::assertSame($requestsBefore, count($this->api->requests), 'nothing is sent to Supertext');
        self::assertSame('Unsere Dienstleistungen', PageFile::read($this->folder . '/default.de.md')->header['title']);

        $result = $this->translate(['de', 'fr'], true);
        self::assertSame('updated', $result['de']['result']);
        self::assertSame('updated', $result['fr']['result']);
        self::assertSame('OUR **SERVICES**', PageFile::read($this->folder . '/default.de.md')->header['title']);
    }

    public function testErrorsAreReportedPerLanguage(): void
    {
        $this->api->failFor['fr-FR'] = 'error';
        $result = $this->translate();
        self::assertSame('created', $result['de']['result']);
        self::assertSame('error', $result['fr']['result']);
        self::assertStringContainsString('could not translate', $result['fr']['message']);
        self::assertSame('translation_failed', $result['fr']['reason']);
        self::assertFileDoesNotExist($this->folder . '/default.fr.md');
    }

    public function testFallsBackToFileWithoutLanguageSuffix(): void
    {
        rename($this->folder . '/default.en.md', $this->folder . '/default.md');
        self::assertSame('created', $this->translate(['de'])['de']['result']);
    }

    public function testPublishSettingAndOwnHeaderFields(): void
    {
        $this->translator = $this->makeTranslator(new Settings(apiKey: 'k', languageCodes: self::MAPPING, headerFields: ['title'], publishNewTranslations: true));
        $this->translate(['de']);
        $de = PageFile::read($this->folder . '/default.de.md');
        self::assertArrayNotHasKey('published', $de->header);
        self::assertSame('Services', $de->header['menu']);
    }

    public function testUnmappedLanguageIsNotSentAndTheOthersAreTranslated(): void
    {
        $this->translator = $this->makeTranslator(new Settings(apiKey: 'k', languageCodes: ['de' => 'de-CH']));
        $result = $this->translate(['de', 'fr', 'gsw', 'es']);

        self::assertSame('created', $result['de']['result']);
        self::assertSame('created', $result['fr']['result'], 'fr has a default: fr-FR');
        self::assertSame('created', $result['es']['result'], 'es is a Supertext code as it is');
        self::assertSame('error', $result['gsw']['result']);
        self::assertSame('unsupported_language', $result['gsw']['reason']);
        self::assertSame(['gsw'], $result['gsw']['args']);
        self::assertStringContainsString('has no Supertext language yet', $result['gsw']['message']);
        self::assertFileDoesNotExist($this->folder . '/default.gsw.md');
        $targets = array_map(static fn(array $r): string => preg_match('/name="target_lang"\r\n\r\n([^\r]*)/', (string)$r['body'], $m) ? $m[1] : '', array_filter($this->api->requests, static fn(array $r): bool => $r['method'] === 'POST'));
        self::assertSame(['de-CH', 'fr-FR', 'es'], array_values($targets), 'nothing is sent for the unmapped language');
    }

    public function testUnmappedSourceStopsBeforeAnythingIsSent(): void
    {
        rename($this->folder . '/default.en.md', $this->folder . '/default.xx.md');
        try {
            $this->translator->translate($this->folder, 'default', 'xx', ['de'], false);
            self::fail('expected an error');
        } catch (SupertextException $e) {
            self::assertSame('unsupported_language', $e->reason);
            self::assertSame(['xx'], $e->args);
        }
        self::assertSame([], $this->api->requests);
    }

    public function testFormalAddressOnlyWhereSupertextOffersIt(): void
    {
        $this->translator = $this->makeTranslator(new Settings(apiKey: 'k', languageCodes: ['de' => 'de-CH', 'en' => 'en-US'], politeness: 'more'));
        file_put_contents($this->folder . '/default.fr.md', (string)file_get_contents($this->folder . '/default.en.md'));
        $this->translator->translate($this->folder, 'default', 'fr', ['de', 'en'], true); // default.en.md exists: replace it
        $bodies = array_values(array_map(static fn(array $r): string => (string)$r['body'], array_filter($this->api->requests, static fn(array $r): bool => $r['method'] === 'POST')));
        self::assertStringContainsString("name=\"politeness\"\r\n\r\nmore", $bodies[0]);
        self::assertStringNotContainsString('name="politeness"', $bodies[1], 'en-US has no formal form: Supertext decides');
    }

    public function testSettingsFromConfigAndEnvironment(): void
    {
        $settings = Settings::fromArray(['language_codes' => [['grav' => 'FR', 'supertext' => 'fr-CH']], 'api_key' => ''], 'Supertext-Auth-Key env');
        self::assertSame('fr-CH', $settings->targetCode('fr'));
        self::assertSame('de-CH', $settings->targetCode('de-ch'));
        self::assertSame('it-IT', $settings->targetCodeOrNull('it'), 'the SDK default for a bare it');
        self::assertNull($settings->targetCodeOrNull('de-li'), 'no guessed region: de-LI needs a mapping');
        self::assertSame('fr', $settings->sourceCode('fr'));
        self::assertSame('de', $settings->sourceCode('de-ch'));
        self::assertSame('env', $settings->apiKey);
    }

    private function state(string $lang): string
    {
        return $this->translator->status($this->folder, 'default', 'en', [$lang])['languages'][$lang]['state'];
    }

    private function edit(string $lang, callable $change): void
    {
        $file = $this->folder . '/default.' . $lang . '.md';
        $page = PageFile::read($file);
        $change($page);
        $page->write($file);
    }

    public function testLanguageWithTranslationReviewGoesThroughTheReviewService(): void
    {
        $settings = Settings::fromArray(['api_key' => 'k', 'language_codes' => ['de' => ['code' => 'de-CH', 'review' => true], 'fr' => 'fr-FR']]);
        $client = SupertextClient::fromSettings($settings, 'Grav-Supertext-Translation/test', $this->api, static function (): void {});
        $translator = new PageTranslator($client, $settings, static fn(): string => '2026-10-05T20:00:00Z');

        $result = $translator->translate($this->folder, 'default', 'en', ['de', 'fr']);
        self::assertSame('created', $result['de']['result']);
        self::assertSame('created', $result['fr']['result']);
        $hosts = [];
        foreach ($this->api->requests as $request) {
            $hosts[(string)parse_url($request['url'], PHP_URL_HOST)][] = $request['method'];
        }
        self::assertSame(['POST', 'GET', 'GET', 'DELETE'], $hosts['localizationreview-production.up.railway.app'], 'German: upload, status, download, delete at the review service');
        self::assertSame(['POST', 'GET', 'GET', 'DELETE'], $hosts['api.supertext.com'], 'French: directly to Supertext');
        self::assertStringContainsString('de-CH', (string)$this->api->requests[0]['body']);
    }
}
