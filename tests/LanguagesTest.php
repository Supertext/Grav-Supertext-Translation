<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * languages.yaml has every string in English, German, French and Italian, and every key the
 * settings (blueprints.yaml), the panel (admin-next/…) and the PHP code use.
 */
final class LanguagesTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';
    private const PREFIX = 'PLUGIN_SUPERTEXT_TRANSLATION.';

    /** @return array<string, string> flattened keys of one language */
    private static function strings(string $language): array
    {
        $all = Yaml::parseFile(self::ROOT . '/languages.yaml');
        self::assertSame(['en', 'de', 'fr', 'it'], array_keys($all));

        return self::flatten($all[$language]);
    }

    /** @return array<string, string> */
    private static function flatten(array $tree, string $prefix = ''): array
    {
        $out = [];
        foreach ($tree as $key => $value) {
            if (is_array($value)) {
                $out += self::flatten($value, $prefix . $key . '.');
            } else {
                $out[$prefix . $key] = (string)$value;
            }
        }

        return $out;
    }

    /** @return iterable<string, array{string}> */
    public static function languages(): iterable
    {
        foreach (['de', 'fr', 'it'] as $language) {
            yield $language => [$language];
        }
    }

    #[DataProvider('languages')]
    public function testSameKeysAndPlaceholdersAsEnglish(string $language): void
    {
        $english = self::strings('en');
        $strings = self::strings($language);

        self::assertSame(array_keys($english), array_keys($strings));
        foreach ($english as $key => $text) {
            self::assertSame(self::placeholders($text), self::placeholders($strings[$key]), "$language $key");
            if (str_starts_with($key, 'ICU.')) {
                // A straight apostrophe starts a quoted section in ICU MessageFormat.
                self::assertStringNotContainsString("'", $strings[$key], "$language $key");
            }
        }
    }

    public function testSettingsLabelsExist(): void
    {
        $english = self::strings('en');
        preg_match_all('/PLUGIN_SUPERTEXT_TRANSLATION\.[A-Z0-9_.]*[A-Z0-9_]/', (string)file_get_contents(self::ROOT . '/blueprints.yaml'), $m);
        self::assertGreaterThan(20, count($m[0]));
        foreach ($m[0] as $key) {
            self::assertArrayHasKey($key, $english);
        }
    }

    public function testPanelStringsExistAndMatchTheEnglishFallback(): void
    {
        $panel = [];
        foreach (self::strings('en') as $key => $text) {
            if (str_starts_with($key, 'ICU.' . self::PREFIX . 'PANEL.')) {
                $panel[substr($key, strlen('ICU.' . self::PREFIX . 'PANEL.'))] = $text;
            }
        }
        $js = (string)file_get_contents(self::ROOT . '/admin-next/panels/supertext-translation.js');

        self::assertMatchesRegularExpression('/const EN = \{\n(.*?)\n\};/s', $js);
        preg_match('/const EN = \{\n(.*?)\n\};/s', $js, $block);
        preg_match_all("/^    ([A-Z_]+): '((?:[^'\\\\]|\\\\.)*)',$/m", $block[1], $entries, PREG_SET_ORDER);
        $fallback = [];
        foreach ($entries as $entry) {
            $fallback[$entry[1]] = stripslashes($entry[2]);
        }
        self::assertSame($panel, $fallback);

        preg_match_all("/(?:\\bt|_html)\\('([A-Z_]+)'|\\bkey: '([A-Z_]+)'|: '((?:STATE|FIELD|RESULT)_[A-Z_]+)'/", $js, $m);
        $used = array_unique(array_filter(array_merge($m[1], $m[2], $m[3])));
        self::assertGreaterThan(30, count($used));
        foreach ($used as $key) {
            self::assertArrayHasKey($key, $panel);
        }
    }

    public function testEveryMessageOfThePhpCodeExists(): void
    {
        $english = self::strings('en');
        $code = '';
        foreach (['classes/Api/SupertextClient.php', 'classes/Api/CurlTransport.php', 'classes/PageTranslator.php', 'classes/Controller/TranslationController.php', 'supertext-translation.php'] as $file) {
            $code .= file_get_contents(self::ROOT . '/' . $file);
        }
        preg_match_all("/because\\('([a-z_]+)'|=> \\['([a-z_]+)', '|'reason' => (?:\\\$state[^?]*\\? )?'([a-z_]+)'(?: : '([a-z_]+)')?|->reason\\('([a-z_]+)'/", $code, $m);
        $reasons = array_unique(array_filter(array_merge($m[1], $m[2], $m[3], $m[4], $m[5])));
        self::assertGreaterThan(15, count($reasons));
        foreach ($reasons as $reason) {
            self::assertArrayHasKey(self::PREFIX . 'ERRORS.' . strtoupper($reason), $english, $reason);
        }
        preg_match_all("/->text\\('([A-Z_.]+)'/", $code, $m);
        self::assertNotEmpty($m[1]);
        foreach ($m[1] as $key) {
            self::assertArrayHasKey(self::PREFIX . $key, $english);
        }
    }

    /** @return list<string> */
    private static function placeholders(string $text): array
    {
        preg_match_all('/%[sd]|\{[a-z]+\}|<\/?[a-z]+|https?:\/\/[^\s"<]+|SUPERTEXT_[A-Z_]+/', $text, $m);
        $found = $m[0];
        sort($found);

        return $found;
    }
}
