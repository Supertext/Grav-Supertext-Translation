<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Markdown;

/**
 * Converts one line of inline Markdown to HTML for Supertext and back.
 *
 * Formatting becomes inline tags (<b>, <i>, <s>, <a href>), so a whole sentence
 * travels as one unit and the translator can move the formatting with the words.
 *
 * Things that must not change (code spans, images, Twig, autolinks, inline HTML
 * comments) are replaced by protected elements: <code data-k="N"> for code-like
 * tokens (their text stays readable for the translator) and <img data-k="N"> for
 * the rest. When the translation comes back, every protected element is swapped
 * for its original source, whatever happened to its text.
 */
final class InlineConverter
{
    /** @var list<string> original Markdown of each protected token, by index */
    private array $protected = [];

    public function toHtml(string $markdown): string
    {
        return $this->parse($markdown);
    }

    /** @return list<string> */
    public function protectedTokens(): array
    {
        return $this->protected;
    }

    /**
     * @param list<string> $protected tokens returned by protectedTokens() for the same segment
     * @param string $lineBreak Markdown written for a <br> (depends on the block the text sits in)
     */
    public static function toMarkdown(string $html, array $protected, string $lineBreak = "  \n"): string
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><body><div id="root">' . $html . '</div></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $dom->getElementById('root');
        if ($root === null) {
            return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $markdown = self::children($root, $protected, $lineBreak);
        // Tidy spaces the translator left inside or around formatting.
        $markdown = preg_replace('/[ \t]{2,}(?!\n)/u', ' ', $markdown) ?? $markdown;
        return trim($markdown, " \t");
    }

    // ---------------------------------------------------------------- Markdown → HTML

    private function parse(string $text): string
    {
        $out = '';
        $length = strlen($text);
        $i = 0;
        $plain = '';

        $flush = function () use (&$out, &$plain): void {
            if ($plain !== '') {
                $out .= htmlspecialchars($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $plain = '';
            }
        };

        while ($i < $length) {
            $rest = substr($text, $i);
            $char = $text[$i];

            // Backslash escape: keep the escaped character as text.
            if ($char === '\\' && $i + 1 < $length && str_contains('\\`*_{}[]()#+-.!|<>~"\'', $text[$i + 1])) {
                $plain .= $text[$i + 1];
                $i += 2;
                continue;
            }
            // Hard line break written as a trailing backslash or two spaces is handled by the block parser (<br> in the text).
            if (str_starts_with($rest, '<br>')) {
                $flush();
                $out .= '<br>';
                $i += 4;
                continue;
            }
            // Twig expressions and tags.
            if (preg_match('/^(\{\{.*?\}\}|\{%.*?%\}|\{#.*?#\})/s', $rest, $m)) {
                $flush();
                $out .= $this->protect($m[1], 'img');
                $i += strlen($m[1]);
                continue;
            }
            // Code span.
            if ($char === '`' && preg_match('/^(`+)(.+?)(?<!`)\1(?!`)/s', $rest, $m)) {
                $flush();
                $out .= $this->protect($m[0], 'code', trim($m[2]));
                $i += strlen($m[0]);
                continue;
            }
            // Image (optionally a linked image).
            if (preg_match('/^(\[!\[[^\]]*\]\([^)]*\)\]\([^)]*\)|!\[[^\]]*\]\([^)]*\)(\{[^}]*\})?)/', $rest, $m)) {
                $flush();
                $out .= $this->protect($m[1], 'img');
                $i += strlen($m[1]);
                continue;
            }
            // Autolink <https://…> / <mail@…> and inline HTML comments.
            if (preg_match('/^(<(?:https?:\/\/|mailto:)[^>\s]+>|<[^@>\s]+@[^>\s]+>|<!--.*?-->)/s', $rest, $m)) {
                $flush();
                $out .= $this->protect($m[1], 'code', trim($m[1], '<>'));
                $i += strlen($m[1]);
                continue;
            }
            // Inline HTML tags pass through unchanged (Supertext keeps markup).
            if (preg_match('/^<\/?[a-zA-Z][a-zA-Z0-9-]*(\s[^<>]*)?\/?>/', $rest, $m)) {
                $flush();
                $out .= $m[0];
                $i += strlen($m[0]);
                continue;
            }
            // Link [text](url "title") — the text is translated, url and title kept.
            if ($char === '[' && ($link = $this->matchLink($text, $i)) !== null) {
                $flush();
                [$inner, $href, $title, $consumed] = $link;
                $attrs = ' href="' . htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
                if ($title !== null) {
                    $attrs .= ' title="' . htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
                }
                $out .= '<a' . $attrs . '>' . $this->parse($inner) . '</a>';
                $i += $consumed;
                continue;
            }
            // Strong, emphasis, strikethrough.
            foreach ([['**', 'b'], ['__', 'b'], ['~~', 's'], ['*', 'i'], ['_', 'i']] as [$delimiter, $tag]) {
                if (!str_starts_with($rest, $delimiter)) {
                    continue;
                }
                $close = $this->findClosing($text, $i, $delimiter);
                if ($close === null) {
                    continue;
                }
                $flush();
                $inner = substr($text, $i + strlen($delimiter), $close - $i - strlen($delimiter));
                $out .= '<' . $tag . '>' . $this->parse($inner) . '</' . $tag . '>';
                $i = $close + strlen($delimiter);
                continue 2;
            }

            $plain .= $char;
            $i++;
        }
        $flush();
        return $out;
    }

    private function protect(string $source, string $tag, string $visibleText = ''): string
    {
        $index = count($this->protected);
        $this->protected[] = $source;
        if ($tag === 'code') {
            return '<code data-k="' . $index . '">' . htmlspecialchars($visibleText, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</code>';
        }
        return '<img data-k="' . $index . '" alt="">';
    }

    /** @return array{0: string, 1: string, 2: ?string, 3: int}|null */
    private function matchLink(string $text, int $start): ?array
    {
        // Find the closing bracket of the link text, allowing nested brackets.
        $depth = 0;
        $length = strlen($text);
        for ($j = $start; $j < $length; $j++) {
            $c = $text[$j];
            if ($c === '\\') {
                $j++;
                continue;
            }
            if ($c === '[') {
                $depth++;
            } elseif ($c === ']') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
        }
        if ($j >= $length || ($text[$j + 1] ?? '') !== '(') {
            return null;
        }
        if (!preg_match('/^\(\s*(<[^>]*>|[^\s()]*(?:\([^\s()]*\)[^\s()]*)*)(?:\s+("[^"]*"|\'[^\']*\'))?\s*\)/', substr($text, $j + 1), $m)) {
            return null;
        }
        $inner = substr($text, $start + 1, $j - $start - 1);
        $title = isset($m[2]) && $m[2] !== '' ? substr($m[2], 1, -1) : null;
        return [$inner, trim($m[1], '<>'), $title, ($j + 1 - $start) + strlen($m[0])];
    }

    private function findClosing(string $text, int $start, string $delimiter): ?int
    {
        $d = strlen($delimiter);
        $length = strlen($text);
        $isUnderscore = $delimiter[0] === '_';
        $isSingle = $d === 1;

        // An opener must be followed by non-space; "_" must not sit inside a word.
        $after = $text[$start + $d] ?? '';
        if ($after === '' || ctype_space($after) || ($isSingle && $after === $delimiter)) {
            return null;
        }
        if ($isUnderscore && $start > 0 && self::isWordChar($text[$start - 1])) {
            return null;
        }

        for ($j = $start + $d + 1; $j <= $length - $d; $j++) {
            $c = $text[$j];
            if ($c === '\\') {
                $j++;
                continue;
            }
            if ($c === '`' && preg_match('/^(`+).+?(?<!`)\1(?!`)/s', substr($text, $j), $m)) {
                $j += strlen($m[0]) - 1;
                continue;
            }
            if (substr($text, $j, $d) !== $delimiter) {
                continue;
            }
            if ($isSingle && ($text[$j + 1] ?? '') === $delimiter) {
                // A doubled delimiter inside emphasis is nested strong: skip over it.
                $close = $this->findClosing($text, $j, $delimiter . $delimiter);
                $j = $close !== null ? $close + 1 : $j + 1;
                continue;
            }
            $before = $text[$j - 1];
            if (ctype_space($before)) {
                continue;
            }
            if ($isUnderscore && self::isWordChar($text[$j + $d] ?? '')) {
                continue;
            }
            return $j;
        }
        return null;
    }

    private static function isWordChar(string $c): bool
    {
        return $c !== '' && (ctype_alnum($c) || ord($c) >= 0x80);
    }

    // ---------------------------------------------------------------- HTML → Markdown

    /** @param list<string> $protected */
    private static function children(\DOMNode $node, array $protected, string $lineBreak): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= self::node($child, $protected, $lineBreak);
        }
        return $out;
    }

    /** @param list<string> $protected */
    private static function node(\DOMNode $node, array $protected, string $lineBreak): string
    {
        if ($node instanceof \DOMText) {
            return self::escapeText(preg_replace('/\s+/u', ' ', $node->textContent) ?? $node->textContent);
        }
        if (!$node instanceof \DOMElement) {
            return '';
        }
        $tag = strtolower($node->tagName);
        if ($node->hasAttribute('data-k')) {
            return $protected[(int)$node->getAttribute('data-k')] ?? '';
        }
        switch ($tag) {
            case 'br':
                return $lineBreak;
            case 'b':
            case 'strong':
                return self::wrap('**', self::children($node, $protected, $lineBreak));
            case 'i':
            case 'em':
                return self::wrap('*', self::children($node, $protected, $lineBreak));
            case 's':
            case 'del':
            case 'strike':
                return self::wrap('~~', self::children($node, $protected, $lineBreak));
            case 'a':
                $inner = trim(self::children($node, $protected, $lineBreak));
                $href = $node->getAttribute('href');
                $title = $node->getAttribute('title');
                $target = (preg_match('/[\s()]/', $href) ? '<' . $href . '>' : $href)
                    . ($title !== '' ? ' "' . str_replace('"', '\\"', $title) . '"' : '');
                return '[' . $inner . '](' . $target . ')';
            default:
                // Inline HTML the author wrote: keep the tag, convert what is inside.
                $attrs = '';
                foreach ($node->attributes ?? [] as $attr) {
                    $attrs .= ' ' . $attr->name . '="' . htmlspecialchars($attr->value, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
                }
                $voids = ['img', 'hr', 'wbr', 'input', 'source'];
                if (in_array($tag, $voids, true)) {
                    return '<' . $tag . $attrs . '>';
                }
                return '<' . $tag . $attrs . '>' . self::children($node, $protected, $lineBreak) . '</' . $tag . '>';
        }
    }

    /** Puts the delimiters around the words, leaving surrounding spaces outside. */
    private static function wrap(string $delimiter, string $inner): string
    {
        if (trim($inner) === '') {
            return $inner;
        }
        preg_match('/^(\s*)(.*?)(\s*)$/su', $inner, $m);
        return $m[1] . $delimiter . $m[2] . $delimiter . $m[3];
    }

    private static function escapeText(string $text): string
    {
        // Characters that would start formatting or links in Markdown.
        return preg_replace('/([\\\\`*_\[\]])/', '\\\\$1', $text) ?? $text;
    }
}
