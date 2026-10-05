<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Tests;

use Grav\Plugin\SupertextTranslation\Api\HtmlDocument;
use Grav\Plugin\SupertextTranslation\Markdown\InlineConverter;
use Grav\Plugin\SupertextTranslation\Markdown\MarkdownDocument;
use PHPUnit\Framework\TestCase;

final class MarkdownTest extends TestCase
{
    private const SAMPLE = <<<'MD'
        # Welcome to **Grav**

        This is a paragraph with *emphasis*, a [link](https://example.com "Example") and `code`.
        It continues on a second line.

        - First **item**
        - Second item with ![logo](logo.png)
          continued here
        1. Numbered

        > [!NOTE]
        > Notes stay notes.

        | Name | Description |
        | ---- | ----------- |
        | Grav | A flat-file CMS |

        ```php
        echo "not translated";
        ```

        <div class="hero">Big words</div>

        {% include 'partials/x.html.twig' %}
        [notice]
        Shortcode content is text.
        [/notice]

        [ref]: https://example.com
        MD;

    public function testSegmentsAreWholeBlocks(): void
    {
        $doc = MarkdownDocument::parse(self::SAMPLE);
        $html = array_column($doc->segments(), 'html');

        self::assertSame([
            'Welcome to <b>Grav</b>',
            'This is a paragraph with <i>emphasis</i>, a <a href="https://example.com" title="Example">link</a> and <code data-k="0">code</code>. It continues on a second line.',
            'First <b>item</b>',
            'Second item with <img data-k="0" alt=""> continued here',
            'Numbered',
            'Notes stay notes.',
            'Name',
            'Description',
            'Grav',
            'A flat-file CMS',
            '<div class="hero">Big words</div>',
            'Shortcode content is text.',
        ], $html);
    }

    public function testRenderWithoutTranslationKeepsTheSource(): void
    {
        $source = "## Title\n\nOne line.\n\n```\ncode\n```\n\n- a *b*\n- c\n";
        self::assertSame($source, MarkdownDocument::parse($source)->render());
    }

    public function testTranslatedFormattingMapsBackToMarkdown(): void
    {
        $doc = MarkdownDocument::parse("Read the **user guide** and run `bin/grav` [now](https://x.test).\n");
        $out = $doc->render([0 => 'Lies die <b>Anleitung</b> und starte <code data-k="0">BIN/GRAV</code> <a href="https://x.test">jetzt</a>.']);
        self::assertSame("Lies die **Anleitung** und starte `bin/grav` [jetzt](https://x.test).\n", $out);
    }

    public function testCodeAndImagesAreRestoredFromTheSource(): void
    {
        $doc = MarkdownDocument::parse("Logo: ![Logo](a.png?resize=10,10) and `x = 1`\n");
        $out = $doc->render([0 => 'Le logo : <img data-k="0" alt=""> et <code data-k="1">X = 1</code>']);
        self::assertSame("Le logo : ![Logo](a.png?resize=10,10) et `x = 1`\n", $out);
    }

    public function testParagraphStartingWithBlockSyntaxIsEscaped(): void
    {
        $doc = MarkdownDocument::parse("Version 2 is here.\n");
        self::assertSame("\\# 2 ist da.\n", $doc->render([0 => '# 2 ist da.']));
    }

    public function testTextWithMarkdownCharactersIsEscaped(): void
    {
        $doc = MarkdownDocument::parse("Hello\n");
        self::assertSame("5 \\* 3 \\[a\\]\n", $doc->render([0 => '5 * 3 [a]']));
    }

    public function testHeadingAttributesAndTwigAreKept(): void
    {
        $doc = MarkdownDocument::parse("## Contact {#contact}\n\nCall {{ config.site.phone }} today.\n");
        self::assertSame(['Contact', 'Call <img data-k="0" alt=""> today.'], array_column($doc->segments(), 'html'));
        self::assertSame(
            "## Kontakt {#contact}\n\nRufen Sie {{ config.site.phone }} heute an.\n",
            $doc->render([0 => 'Kontakt', 1 => 'Rufen Sie <img data-k="0" alt=""> heute an.']),
        );
    }

    public function testListAndQuoteLineBreaksKeepTheirPrefix(): void
    {
        $doc = MarkdownDocument::parse("> First line  \n> second line\n");
        self::assertSame("> Erste Zeile  \n> zweite Zeile\n", $doc->render([0 => 'Erste Zeile<br>zweite Zeile']));
    }

    public function testNestedEmphasis(): void
    {
        $converter = new InlineConverter();
        self::assertSame('<i>a <b>b</b> c</i> and snake_case_name', $converter->toHtml('*a **b** c* and snake_case_name'));
        self::assertSame('*a **b** c* and snake\_case\_name', InlineConverter::toMarkdown('<i>a <b>b</b> c</i> and snake_case_name', []));
    }

    public function testLinesWithoutWordsAreNotSent(): void
    {
        $doc = MarkdownDocument::parse("![only an image](x.png)\n\n---\n\n`code only`\n\n| 1 | 2 |\n");
        self::assertSame([], $doc->segments());
        self::assertSame("![only an image](x.png)\n\n---\n\n`code only`\n\n| 1 | 2 |\n", $doc->render());
    }

    public function testHtmlDocumentRoundTripKeepsUnicode(): void
    {
        $html = HtmlDocument::build([['html' => 'Grüezi <b>mitenand</b> – «Hallo»', 'tag' => 'p'], ['html' => HtmlDocument::text("A & B\nC"), 'tag' => 'h1']], 'en');
        self::assertStringContainsString('<p data-st-id="0">Grüezi <b>mitenand</b> – «Hallo»</p>', $html);
        $parsed = HtmlDocument::parse($html);
        self::assertSame('Grüezi <b>mitenand</b> – «Hallo»', $parsed[0]);
        self::assertSame("A & B\nC", HtmlDocument::plain($parsed[1]));
    }
}
