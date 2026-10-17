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
 * The mapping wins, else an exact Supertext code, else the SDK's default (de → de-DE), else
 * unsupported_language.
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
        yield 'default for a bare de' => ['de', [], 'de-DE'];
        yield 'default for a bare en' => ['en', [], 'en-US'];
        yield 'default for a bare zh' => ['zh', [], 'zh-Hans'];
        yield 'default for zh-tw' => ['zh-tw', [], 'zh-Hant'];
        yield 'region of a bare target' => ['es-ES', [], 'es'];
        yield 'mapping wins over the default' => ['de', ['de' => 'de-CH'], 'de-CH'];
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
        yield 'no guessed region for en-IE' => ['en-IE'];
        yield 'no guessed region for de-LI' => ['de-LI'];
        yield 'Swiss German needs a dialect' => ['gsw'];
        yield 'sr-RS needs a script' => ['sr-RS'];
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
        self::assertSame(['de' => 'de-AT'], Settings::fromArray(['language_codes' => [['grav' => 'de', 'supertext' => 'de-AT']]])->languageCodes);
        $rows = Settings::fromArray(['language_codes' => [['grav' => 'de', 'supertext' => 'de-AT']]]);
        self::assertSame('de-AT', $rows->targetCode('de'));
    }

    public function testSanitizeLanguageCodesOnSave(): void
    {
        self::assertSame(
            ['de' => 'de-CH', 'fr-be' => 'fr-FR', 'zh' => 'zh-Hant'],
            Settings::sanitizeLanguageCodes(['de' => ' de_ch ', 'fr-be' => 'fr-FR', 'it' => '', 'xx' => 'xx-YY', 'zh' => 'ZH-hant']),
        );
        // The older list of rows becomes a map, nothing configured is lost.
        self::assertSame(
            ['de' => 'de-CH', 'en' => 'en-GB'],
            Settings::sanitizeLanguageCodes([['grav' => 'de', 'supertext' => 'de-CH'], ['grav' => 'en', 'supertext' => 'en-GB'], ['grav' => '', 'supertext' => 'fr-FR']]),
        );
        self::assertSame([], Settings::sanitizeLanguageCodes(null));
        self::assertSame([], Settings::sanitizeLanguageCodes('de=de-CH'));
    }
}
