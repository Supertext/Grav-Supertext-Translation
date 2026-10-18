<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Tests;

use Grav\Plugin\SupertextTranslation\Api\SupertextClient;
use Grav\Plugin\SupertextTranslation\FileExchange;
use Grav\Plugin\SupertextTranslation\PageFile;
use Grav\Plugin\SupertextTranslation\PageTranslator;
use Grav\Plugin\SupertextTranslation\Settings;
use PHPUnit\Framework\TestCase;
use Supertext\GravTranslation\Sdk\Files\FileJob;
use Supertext\GravTranslation\Sdk\Files\FileSegment;
use Supertext\GravTranslation\Sdk\Files\TranslationFileError;
use Supertext\GravTranslation\Sdk\Files\TranslationFiles;
use Supertext\GravTranslation\Sdk\V1\Client;
use Supertext\GravTranslation\Sdk\V1\Protocol;

/**
 * Export for translation / Import translation: the glue (FileExchange) on a real page folder with
 * PageTranslator::fileSegments() and writeImported() (TranslationController only adds Grav's
 * routes, permissions and messages).
 */
final class FileExchangeTest extends TestCase
{
    private string $folder;
    private FakeSupertext $api;
    private PageTranslator $translator;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/st-grav-files-' . bin2hex(random_bytes(4));
        mkdir($this->folder);
        file_put_contents($this->folder . '/default.en.md', <<<'MD'
            ---
            title: 'Our services'
            menu: Services
            ---

            # We translate

            Supertext translates **your website** into [many languages](https://supertext.com).

            MD);
        $this->api = new FakeSupertext();
        $sdk = new Client('k', Protocol::LIVE_BASE_URL, pollInterval: 1.0, timeout: 60.0, transport: $this->api, sleep: static function (): void {});
        $this->translator = new PageTranslator(new SupertextClient($sdk), new Settings(apiKey: 'k', languageCodes: ['de' => 'de-CH']), static fn(): string => '2026-10-18T10:00:00Z');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->folder . '/*') ?: []);
        rmdir($this->folder);
    }

    /** @return list<FileSegment> */
    private function segments(): array
    {
        return $this->translator->fileSegments($this->folder, 'default', 'en') ?? [];
    }

    /** FileExchange::import()'s $open for the test page. */
    private function open(bool $overwrite = false): \Closure
    {
        return fn(string $route, string $lang): array => $route !== '/services' ? ['error' => FileExchange::NOT_FOUND] : [
            'title' => 'Our services',
            'segments' => $this->segments(),
            'write' => fn(array $texts): array => $this->translator->writeImported($this->folder, 'default', 'en', $lang, $texts, $overwrite),
        ];
    }

    public function testReferenceRoundTrip(): void
    {
        self::assertSame('page:/blog/hello:fr', FileExchange::reference('/blog/hello/', 'fr'));
        self::assertSame(['route' => '/blog/hello', 'language' => 'fr'], FileExchange::parseReference('page:/blog/hello:fr'));
        self::assertSame(['route' => '/', 'language' => 'de'], FileExchange::parseReference(FileExchange::reference('/', 'de')));
        foreach (['', 'page:/blog', 'page:blog:fr', 'entry:/blog:fr', 'page:/blog:fr:x', 'page:/my blog:fr'] as $invalid) {
            self::assertNull(FileExchange::parseReference($invalid), $invalid);
        }
    }

    public function testExportHasThePagesSegmentsAndLanguages(): void
    {
        self::assertSame(['field.title', 'field.menu', 'body.0', 'body.1'], array_map(static fn(FileSegment $s) => $s->key, $this->segments()));
        self::assertSame([false, false, true, true], array_map(static fn(FileSegment $s) => $s->html, $this->segments()));

        $file = FileExchange::export('/services', 'en', [['language' => 'de', 'code' => 'de-CH'], ['language' => 'gsw', 'code' => 'gsw']], $this->segments(), FileExchange::FORMAT_XLIFF);
        self::assertSame('grav-services.xlf', $file['filename']);
        self::assertSame('application/x-xliff+xml', $file['contentType']);
        $jobs = FileExchange::read($file['content']);
        self::assertSame([FileExchange::PLUGIN, 'page:/services:de', 'en', 'de-CH'], [$jobs[0]->plugin, $jobs[0]->reference, $jobs[0]->sourceLanguage, $jobs[0]->targetLanguage]);
        self::assertSame(['page:/services:gsw', 'gsw'], [$jobs[1]->reference, $jobs[1]->targetLanguage]);

        $html = FileExchange::export('/services', 'en', [['language' => 'de', 'code' => 'de-CH']], $this->segments(), FileExchange::FORMAT_HTML);
        self::assertSame(['grav-services-de.html', 'text/html; charset=utf-8'], [$html['filename'], $html['contentType']]);
        self::assertNull(FileExchange::export('/services', 'en', [['language' => 'de', 'code' => 'de-CH']], [new FileSegment('body.0', ' ')], FileExchange::FORMAT_XLIFF));

        $this->expectException(\InvalidArgumentException::class);
        FileExchange::export('/services', 'en', [['language' => 'de', 'code' => 'de-CH'], ['language' => 'fr', 'code' => 'fr-FR']], $this->segments(), FileExchange::FORMAT_HTML);
    }

    public function testImportWritesAnUnpublishedTranslation(): void
    {
        $html = FileExchange::export('/services', 'en', [['language' => 'de', 'code' => 'de-CH']], $this->segments(), FileExchange::FORMAT_HTML)['content'];
        $translated = str_replace(['Our services', '>Services<', 'We translate', 'translates', 'your website', 'into', 'many languages'], ['Unsere Dienste', '>Dienste<', 'Wir übersetzen', 'übersetzt', 'Ihre Website', 'in', 'viele Sprachen'], $html);

        $result = FileExchange::import(FileExchange::read($translated)[0], false, $this->open());

        self::assertNull($result['error']);
        self::assertSame(4, $result['imported']);
        self::assertSame('created', $result['write']['result']);
        self::assertSame([], $this->api->requests, 'no API call');
        $de = PageFile::read($this->folder . '/default.de.md');
        self::assertSame('Unsere Dienste', $de->header['title']);
        self::assertSame('Dienste', $de->header['menu']);
        self::assertFalse($de->header['published']);
        self::assertSame('de-CH', $de->header['supertext']['language']);
        self::assertStringContainsString('# Wir übersetzen', $de->body);
        self::assertStringContainsString('Supertext übersetzt **Ihre Website** in [viele Sprachen](https://supertext.com).', $de->body);
    }

    public function testSourceChangedOnlyWithTheOption(): void
    {
        $xliff = self::translateXliff(FileExchange::export('/services', 'en', [['language' => 'de', 'code' => 'de-CH']], $this->segments(), FileExchange::FORMAT_XLIFF)['content']);
        $source = PageFile::read($this->folder . '/default.en.md');
        $source->header['title'] = 'Our services and prices';
        $source->write($this->folder . '/default.en.md');

        $skipped = FileExchange::import(FileExchange::read($xliff)[0], false, $this->open());
        self::assertSame(1, $skipped['counts']['source_changed']);
        self::assertSame(3, $skipped['imported']);
        self::assertSame('Our services and prices', PageFile::read($this->folder . '/default.de.md')->header['title'], 'not imported: source text');

        $included = FileExchange::import(FileExchange::read($xliff)[0], true, $this->open());
        self::assertSame(4, $included['imported']);
        self::assertSame('DE Our services', PageFile::read($this->folder . '/default.de.md')->header['title']);
    }

    public function testUntranslatedInvalidAndMissingAreNotWritten(): void
    {
        $html = FileExchange::export('/services', 'en', [['language' => 'de', 'code' => 'de-CH']], $this->segments(), FileExchange::FORMAT_HTML)['content'];
        $html = str_replace('<b>your website</b>', '<script>x</script>Ihre Website', $html);
        file_put_contents($this->folder . '/default.en.md', "\nA paragraph added after the export.\n", FILE_APPEND);

        $result = FileExchange::import(FileExchange::read($html)[0], true, $this->open());
        self::assertSame(1, $result['counts']['invalid_markup']);
        self::assertSame(1, $result['counts']['missing']);
        self::assertSame(3, $result['imported']);
        self::assertStringNotContainsString('script', (string)file_get_contents($this->folder . '/default.de.md'));

        // XLIFF without translations: untranslated, nothing written.
        unlink($this->folder . '/default.de.md');
        $xliff = FileExchange::export('/services', 'en', [['language' => 'de', 'code' => 'de-CH']], $this->segments(), FileExchange::FORMAT_XLIFF)['content'];
        $result = FileExchange::import(FileExchange::read($xliff)[0], true, $this->open());
        self::assertSame(5, $result['counts']['untranslated']);
        self::assertSame(0, $result['imported']);
        self::assertFileDoesNotExist($this->folder . '/default.de.md');
    }

    public function testATranslationEditedByHandIsOnlyReplacedWithOverwrite(): void
    {
        $xliff = self::translateXliff(FileExchange::export('/services', 'en', [['language' => 'de', 'code' => 'de-CH']], $this->segments(), FileExchange::FORMAT_XLIFF)['content']);
        file_put_contents($this->folder . '/default.de.md', "---\ntitle: Von Hand\n---\n\nVon Hand übersetzt.\n");

        $kept = FileExchange::import(FileExchange::read($xliff)[0], false, $this->open());
        self::assertSame(['skipped', 'kept_manual'], [$kept['write']['result'], $kept['write']['reason']]);
        self::assertSame(0, $kept['imported']);
        self::assertSame('Von Hand', PageFile::read($this->folder . '/default.de.md')->header['title']);

        $replaced = FileExchange::import(FileExchange::read($xliff)[0], false, $this->open(true));
        self::assertSame('updated', $replaced['write']['result']);
        self::assertSame('DE Our services', PageFile::read($this->folder . '/default.de.md')->header['title']);
    }

    public function testRefusesOtherSystemsUnknownReferencesAndLoadingErrors(): void
    {
        $file = static fn(string $plugin, string $reference) => FileExchange::read(TranslationFiles::exportHtml(
            new FileJob($plugin, $reference, 'en', 'de-CH', [new FileSegment('field.title', 'Our services')]),
        ))[0];

        $other = FileExchange::import($file('Contao-Supertext-Translation', 'page:12:de'), false, $this->open());
        self::assertSame([FileExchange::OTHER_SYSTEM, ['Contao-Supertext-Translation']], [$other['error'], $other['args']]);
        self::assertSame(FileExchange::NOT_FOUND, FileExchange::import($file(FileExchange::PLUGIN, 'page:12:de'), false, $this->open())['error']);
        self::assertSame(FileExchange::NOT_FOUND, FileExchange::import($file(FileExchange::PLUGIN, 'page:/gone:de'), false, $this->open())['error']);
        foreach ([FileExchange::NO_LANGUAGE, FileExchange::FORBIDDEN] as $error) {
            $result = FileExchange::import($file(FileExchange::PLUGIN, 'page:/services:it'), false, static fn() => ['error' => $error, 'args' => ['it']]);
            self::assertSame([$error, ['it']], [$result['error'], $result['args']]);
        }
        self::assertFileDoesNotExist($this->folder . '/default.de.md');
    }

    public function testTooLargeFilesAreRefused(): void
    {
        self::assertSame(10 * 1024 * 1024, FileExchange::MAX_UPLOAD_BYTES);
        try {
            FileExchange::read(str_repeat('x', TranslationFiles::MAX_FILE_CHARACTERS + 1));
            self::fail('expected too_large');
        } catch (TranslationFileError $e) {
            self::assertSame(TranslationFileError::TOO_LARGE, $e->errorCode);
        }
    }

    /** Fills every XLIFF target with "DE " + source (inline codes kept). */
    private static function translateXliff(string $xliff): string
    {
        return (string)preg_replace_callback('~<source>(.*?)</source>~s', static fn(array $m) => '<source>' . $m[1] . '</source><target>DE ' . $m[1] . '</target>', $xliff);
    }
}
