<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Tests;

use Grav\Plugin\SupertextTranslation\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Supertext\GravTranslation\Sdk\V1\SupertextException as SdkException;

/**
 * Grav language codes => Supertext codes, through the plugin's setting (language_codes) and
 * the bundled SDK's Languages (whose own rules are tested in the SDK against spec/languages.json).
 * No guessing: the mapping wins, else an exact Supertext code, else unsupported_language.
 */
final class LanguageCodesTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, string>, string}> */
    public static function targets(): iterable
    {
        yield 'mapping wins' => ['de', ['de' => 'de-CH'], 'de-CH'];
        yield 'mapping is case-insensitive' => ['DE', ['de' => 'de-AT'], 'de-AT'];
        yield 'mapping value sent as it is' => ['no', ['no' => 'nb'], 'nb'];
        yield 'exact regional code' => ['de-ch', [], 'de-CH'];
        yield 'underscore and case' => ['fr_fr', [], 'fr-FR'];
        yield 'bare Supertext target' => ['es', [], 'es'];
        yield 'script' => ['zh-hant', [], 'zh-Hant'];
        yield 'blank mapping ignored' => ['nl', ['nl' => ' '], 'nl'];
    }

    /** @param array<string, string> $mapping */
    #[DataProvider('targets')]
    public function testTarget(string $code, array $mapping, string $expected): void
    {
        $settings = new Settings(apiKey: 'k', languageCodes: $mapping);

        self::assertSame($expected, $settings->targetCode($code));
        self::assertSame($expected, $settings->targetCodeOrNull($code));
    }

    /** @return iterable<string, array{string}> */
    public static function unmapped(): iterable
    {
        yield 'bare de has no default region any more' => ['de'];
        yield 'bare en' => ['en'];
        yield 'bare zh' => ['zh'];
        yield 'old default es-ES is not a Supertext code' => ['es-ES'];
        yield 'old default sl-SI' => ['sl-SI'];
        yield 'unknown' => ['xx'];
    }

    #[DataProvider('unmapped')]
    public function testUnmappedTargetIsUnsupportedLanguage(string $code): void
    {
        $settings = new Settings(apiKey: 'k');
        self::assertNull($settings->targetCodeOrNull($code));
        try {
            $settings->targetCode($code);
            self::fail('expected unsupported_language');
        } catch (SdkException $e) {
            self::assertSame(SdkException::UNSUPPORTED_LANGUAGE, $e->errorCode);
            self::assertSame($code, $e->targetLang);
        }
    }

    /** @return iterable<string, array{string, array<string, string>, string}> */
    public static function sources(): iterable
    {
        yield 'bare source' => ['en', [], 'en'];
        yield 'regional source => bare' => ['en-us', [], 'en'];
        yield 'underscore and case' => ['DE_ch', [], 'de'];
        yield 'the same mapping, in its source form' => ['de', ['de' => 'de-CH'], 'de'];
        yield 'mapping to another language' => ['en', ['en' => 'fr-CH'], 'fr'];
        yield 'empty: auto-detect' => ['', [], ''];
    }

    /** @param array<string, string> $mapping */
    #[DataProvider('sources')]
    public function testSource(string $code, array $mapping, string $expected): void
    {
        self::assertSame($expected, (new Settings(apiKey: 'k', languageCodes: $mapping))->sourceCode($code));
    }

    public function testUnknownSourceIsUnsupportedLanguage(): void
    {
        $settings = new Settings(apiKey: 'k');
        self::assertNull($settings->sourceCodeOrNull('xx'));
        $this->expectException(SdkException::class);
        $settings->sourceCode('xx');
    }

    public function testFormOfAddressOnlyForTargetsThatHaveIt(): void
    {
        $formal = new Settings(apiKey: 'k', politeness: 'more');
        self::assertSame('more', $formal->politenessFor('de-CH'));
        self::assertSame('default', $formal->politenessFor('en-US'), 'Supertext offers no choice for English');
        self::assertSame('default', $formal->politenessFor('rm'));
        self::assertSame('more', $formal->politenessFor('xx-YY'), 'a code the SDK does not know gets the setting');
        self::assertSame('default', (new Settings(apiKey: 'k'))->politenessFor('de-CH'));
    }

    public function testSettingsFromConfig(): void
    {
        $settings = Settings::fromArray([
            'language_codes' => ['DE' => ' de-CH ', 'fr' => '', 'it' => 'it-CH'],
            'version_reports' => false,
        ]);
        self::assertSame(['de' => 'de-CH', 'it' => 'it-CH'], $settings->languageCodes);
        self::assertFalse($settings->versionReports);
        self::assertTrue(Settings::fromArray(['version_reports' => '1'])->versionReports);
        self::assertNull(Settings::fromArray([])->versionReports, 'not set: the SDK treats it as on');

        // The older list form of the setting still works.
        $rows = Settings::fromArray(['language_codes' => [['grav' => 'de', 'supertext' => 'de-AT']]]);
        self::assertSame('de-AT', $rows->targetCode('de'));
    }
}
