<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Api;

/**
 * Packs the segments of one page into a single HTML document for Supertext and
 * reads the translated document back.
 *
 * Supertext keeps markup and attributes and translates the text of each element
 * carrying data-st-id on its own. A segment is a whole block (paragraph, heading,
 * list item, table cell) with its formatting as inline tags, so sentences stay intact.
 */
final class HtmlDocument
{
    /**
     * @param array<int, array{html: string, tag: string}> $segments id => segment HTML (already escaped) and element name
     */
    public static function build(array $segments, string $sourceLanguage = ''): string
    {
        $lang = $sourceLanguage !== '' ? ' lang="' . htmlspecialchars($sourceLanguage, ENT_QUOTES) . '"' : '';
        $html = "<!DOCTYPE html>\n<html{$lang}><head><meta charset=\"utf-8\"></head><body>\n";
        foreach ($segments as $id => $segment) {
            $tag = preg_match('/^(p|div|h[1-6])$/', $segment['tag']) ? $segment['tag'] : 'div';
            $html .= '<' . $tag . ' data-st-id="' . (int)$id . '">' . $segment['html'] . '</' . $tag . ">\n";
        }
        return $html . "</body></html>\n";
    }

    /** Escapes plain text (a front matter value) so it can travel as a segment. */
    public static function text(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return str_replace("\n", '<br>', htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** Turns a translated plain-text segment back into text. */
    public static function plain(string $html): string
    {
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_map(static fn(string $l): string => trim((string)preg_replace('/[ \t]+/u', ' ', $l)), explode("\n", $text));
        return trim(implode("\n", $lines));
    }

    /** @return array<int, string> segment id => translated inner HTML */
    public static function parse(string $html): array
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $result = [];
        $nodes = (new \DOMXPath($dom))->query('//*[@data-st-id]');
        foreach ($nodes === false ? [] : $nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $inner = '';
            foreach ($node->childNodes as $child) {
                $inner .= (string)$dom->saveHTML($child);
            }
            $result[(int)$node->getAttribute('data-st-id')] = trim($inner);
        }
        return $result;
    }
}
