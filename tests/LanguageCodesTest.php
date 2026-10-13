<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Tests;

use Supertext\GravTranslation\Sdk\LanguageCodes;
use Grav\Plugin\SupertextTranslation\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Grav language codes => Supertext codes, through the plugin's settings and the bundled SDK's
 * LanguageCodes (whose own rules are tested in the SDK against the shared test vectors).
 */
final class LanguageCodesTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function sources(): iterable
    {
        yield 'bare source' => ['en', '', 'en'];
        yield 'regional source => bare' => ['en-us', '', 'en'];
        yield 'underscore and case' => ['DE_ch', '', 'de'];
        yield 'override wins, reduced to its language' => ['en', 'fr-CH', 'fr'];
        yield 'empty' => ['', '', ''];
    }

    #[DataProvider('sources')]
    public function testSource(string $code, string $override, string $expected): void
    {
        self::assertSame($expected, self::settings($code, $override)->sourceCode($code));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function targets(): iterable
    {
        yield 'bare target => default region' => ['de', '', 'de-DE'];
        yield 'bare en => en-US' => ['en', '', 'en-US'];
        yield 'bare zh => zh-CN' => ['zh', '', 'zh-CN'];
        yield 'regional target kept' => ['fr-CH', '', 'fr-CH'];
        yield 'underscore normalized' => ['de_CH', '', 'de-CH'];
        yield 'case normalized' => ['de-ch', '', 'de-CH'];
        yield 'script kept' => ['zh-hant', '', 'zh-Hant'];
        yield 'numeric region kept' => ['es-419', '', 'es-419'];
        yield 'override wins' => ['de', 'de-CH', 'de-CH'];
        yield 'override normalized' => ['de', 'de_at', 'de-AT'];
        yield 'override variant dropped' => ['de', 'de_DE_formal', 'de-DE'];
        yield 'bare override gets default region' => ['de', 'it', 'it-IT'];
        yield 'blank override ignored' => ['fr', ' ', 'fr-FR'];
        yield 'unknown bare code unchanged' => ['rm', '', 'rm'];
        yield 'empty' => ['', '', ''];
    }

    #[DataProvider('targets')]
    public function testTarget(string $code, string $override, string $expected): void
    {
        self::assertSame($expected, self::settings($code, $override)->supertextCode($code));
    }

    public function testEveryDefaultIsRegional(): void
    {
        foreach (LanguageCodes::DEFAULT_REGIONS as $bare => $regional) {
            self::assertStringStartsWith($bare . '-', $regional);
            self::assertSame($regional, LanguageCodes::target($regional));
        }
    }

    public function testSettingsUseTheMappingForTargetAndSource(): void
    {
        $settings = new Settings(apiKey: 'k', languageCodes: ['de' => 'de-CH', 'en' => 'en-GB']);

        self::assertSame('de-CH', $settings->supertextCode('de'));
        self::assertSame('de', $settings->sourceCode('de'));
        self::assertSame('en-GB', $settings->supertextCode('EN'));
        self::assertSame('en', $settings->sourceCode('en'));
        self::assertSame('fr-FR', $settings->supertextCode('fr'));
    }

    private static function settings(string $code, string $override): Settings
    {
        return new Settings(apiKey: 'k', languageCodes: $override !== '' ? [strtolower($code) => $override] : []);
    }
}
