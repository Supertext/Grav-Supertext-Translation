<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Markdown;

/**
 * Splits a page body written in Markdown into translatable segments and puts
 * the translated segments back into the same structure.
 *
 * One segment is one block: a paragraph, a heading, a list item, a table cell,
 * a line of an HTML block. Inline formatting stays inside the segment as HTML
 * tags (see InlineConverter), so sentences are never cut at a bold word.
 *
 * Left exactly as written: front matter (handled elsewhere), fenced code,
 * indented code, Twig tags, shortcode tags, link reference definitions,
 * horizontal rules, table separators, GitHub alert markers ([!NOTE]) and any
 * line without translatable text.
 */
final class MarkdownDocument
{
    /**
     * Rendered document: literal strings and segment references.
     * @var list<string|array{seg: int, escape: bool}>
     */
    private array $parts = [];

    /** @var list<array{html: string, protected: list<string>, lineBreak: string, tag: string, raw: bool}> */
    private array $segments = [];

    /** @var array{prefix: string, cont: string, lines: list<string>, raw: list<string>, list: bool}|null */
    private ?array $buffer = null;

    private bool $inList = false;

    /** Width of the current list item's marker, where its text starts. */
    private int $listIndent = 0;

    public static function parse(string $markdown): self
    {
        $doc = new self();
        $doc->split($markdown);
        return $doc;
    }

    /** @return list<array{html: string, protected: list<string>, lineBreak: string, tag: string, raw: bool}> */
    public function segments(): array
    {
        return $this->segments;
    }

    /**
     * @param array<int, string> $translated segment index => translated HTML (missing ones keep the source)
     */
    public function render(array $translated = []): string
    {
        $out = '';
        foreach ($this->parts as $part) {
            if (is_string($part)) {
                $out .= $part;
                continue;
            }
            $segment = $this->segments[$part['seg']];
            $html = $translated[$part['seg']] ?? $segment['html'];
            if ($segment['raw']) {
                $out .= $html;
                continue;
            }
            $text = InlineConverter::toMarkdown($html, $segment['protected'], $segment['lineBreak']);
            if ($part['escape']) {
                $text = self::escapeBlockStart($text);
            }
            $out .= $text;
        }
        return $out;
    }

    // ---------------------------------------------------------------- splitting

    private function split(string $markdown): void
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $lines = explode("\n", $markdown);
        $fence = null;
        $previousBlank = true;

        foreach ($lines as $index => $line) {
            $newline = $index < count($lines) - 1 ? "\n" : '';

            if ($fence !== null) {
                $this->literal($line . $newline);
                if (preg_match('/^\s*' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}\s*$/', $line)) {
                    $fence = null;
                }
                continue;
            }
            if (preg_match('/^\s*(`{3,}|~{3,})/', $line, $m)) {
                $this->flush();
                $fence = $m[1];
                $this->literal($line . $newline);
                continue;
            }
            if (trim($line) === '') {
                $this->flush();
                $this->literal($line . $newline);
                $previousBlank = true;
                continue;
            }

            // Indented code: four spaces more than the surrounding text, after a blank line.
            $codeIndent = ($this->inList ? $this->listIndent : 0) + 4;
            $indent = strlen(str_replace("\t", '    ', (string)preg_replace('/^(\s*).*$/s', '$1', $line)));
            if ($previousBlank && $this->buffer === null && $indent >= $codeIndent) {
                $this->literal($line . $newline);
                continue;
            }
            $previousBlank = false;

            // Block quote prefix (possibly nested).
            $quote = '';
            if (preg_match('/^(\s{0,3}>\s?)+/', $line, $m)) {
                $quote = $m[0];
                $line = substr($line, strlen($quote));
                if (trim($line) === '') {
                    $this->flush();
                    $this->literal($quote . $line . $newline);
                    continue;
                }
            }

            $this->line($quote, $line, $newline);
        }
        $this->flush();
    }

    private function line(string $quote, string $line, string $newline): void
    {
        $verbatim = [
            '/^\s*\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]\s*$/i', // GitHub alert marker
            '/^\s{0,3}([-*_])(\s*\1){2,}\s*$/',                    // horizontal rule / setext underline
            '/^\s{0,3}=+\s*$/',                                    // setext underline
            '/^\s{0,3}\[[^\]]+\]:\s/',                             // link reference definition
            '/^\s*\{[%#].*$/',                                     // Twig tag or comment
            '/^\s*\{\{.*\}\}\s*$/',                                // a line that is only a Twig expression
            '/^\s*\[\/?[a-zA-Z][\w-]*(\s[^\]]*)?\/?\]\s*$/',       // a line that is only a shortcode tag
            '/^\s*<(script|style|iframe|pre|!--)/i',                // markup that must not be touched
            '/^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/',   // table separator
        ];
        foreach ($verbatim as $pattern) {
            if (preg_match($pattern, $line)) {
                $this->flush();
                $this->literal($quote . $line . $newline);
                return;
            }
        }

        // ATX heading, with optional closing hashes and {#anchor .class} attributes.
        if (preg_match('/^(\s{0,3}(#{1,6})\s+)(.*?)(\s+#+\s*|\s*\{[^}]*\}\s*)?$/', $line, $m)) {
            $this->flush();
            $this->inList = false;
            if (!self::translatable($m[3])) {
                $this->literal($quote . $line . $newline);
                return;
            }
            $this->literal($quote . $m[1]);
            $this->inline($m[3], 'h' . strlen($m[2]), '<br>', false);
            $this->literal(($m[4] ?? '') . $newline);
            return;
        }

        // Table row.
        if (preg_match('/^\s*\|.*\|\s*$/', $line)) {
            $this->flush();
            $this->table($quote, $line, $newline);
            return;
        }

        // HTML block line: Supertext keeps markup, so the line goes as it is.
        if (preg_match('/^\s{0,3}<\/?([a-zA-Z][a-zA-Z0-9-]*)(\s|>|\/>|$)/', $line, $m)
            && in_array(strtolower($m[1]), self::BLOCK_TAGS, true)) {
            $this->flush();
            $this->inList = false;
            if (self::hasText(strip_tags($line))) {
                $this->rawSegment($quote, $line, $newline);
            } else {
                $this->literal($quote . $line . $newline);
            }
            return;
        }

        // List item (bullet, ordered, task).
        if (preg_match('/^(\s*)([-*+]|\d{1,9}[.)])(\s+)(\[[ xX]\]\s+)?(.*)$/', $line, $m)) {
            $this->flush();
            $this->inList = true;
            $this->listIndent = strlen($m[1] . $m[2] . $m[3]);
            $prefix = $m[1] . $m[2] . $m[3] . $m[4];
            $this->buffer = [
                'prefix' => $quote . $prefix,
                'cont' => $quote . str_repeat(' ', strlen($m[1] . $m[2] . $m[3])),
                'lines' => [$m[5]],
                'raw' => [$quote . $line . $newline],
                'list' => true,
            ];
            return;
        }

        // Paragraph text (or continuation of the current paragraph / list item).
        if ($this->buffer !== null && $this->bufferQuoteMatches($quote)) {
            $this->buffer['lines'][] = ltrim($line);
            $this->buffer['raw'][] = $quote . $line . $newline;
            return;
        }
        $this->flush();
        if (!preg_match('/^\s/', $line)) {
            $this->inList = false;
        }
        preg_match('/^(\s*)/', $line, $indent);
        $this->buffer = [
            'prefix' => $quote . $indent[1],
            'cont' => $quote . $indent[1],
            'lines' => [ltrim($line)],
            'raw' => [$quote . $line . $newline],
            'list' => false,
        ];
    }

    private const BLOCK_TAGS = [
        'div', 'p', 'section', 'article', 'aside', 'header', 'footer', 'nav', 'main', 'figure', 'figcaption',
        'blockquote', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'details', 'summary', 'center', 'address', 'hr', 'br',
    ];

    private function bufferQuoteMatches(string $quote): bool
    {
        $bufferQuote = '';
        if ($this->buffer !== null && preg_match('/^(\s{0,3}>\s?)+/', $this->buffer['prefix'], $m)) {
            $bufferQuote = $m[0];
        }
        return trim($bufferQuote) === trim($quote);
    }

    private function flush(): void
    {
        if ($this->buffer === null) {
            return;
        }
        $buffer = $this->buffer;
        $this->buffer = null;

        // Join lines: a trailing backslash or two spaces is a hard line break.
        $text = '';
        $count = count($buffer['lines']);
        foreach ($buffer['lines'] as $i => $line) {
            $last = $i === $count - 1;
            if (!$last && preg_match('/( {2,}|\\\\)$/', $line)) {
                $text .= rtrim(rtrim($line), '\\') . '<br>';
            } else {
                $text .= rtrim($line) . ($last ? '' : ' ');
            }
        }

        if (!self::translatable($text)) {
            $this->literal(implode('', $buffer['raw']));
            return;
        }
        $tail = str_ends_with((string)end($buffer['raw']), "\n") ? "\n" : '';
        $this->literal($buffer['prefix']);
        $this->inline($text, $buffer['list'] ? 'div' : 'p', "  \n" . $buffer['cont'], !$buffer['list']);
        $this->literal($tail);
    }

    private function inline(string $text, string $tag, string $lineBreak, bool $escape): void
    {
        $converter = new InlineConverter();
        $this->segments[] = [
            'html' => $converter->toHtml($text),
            'protected' => $converter->protectedTokens(),
            'lineBreak' => $lineBreak,
            'tag' => $tag,
            'raw' => false,
        ];
        $this->parts[] = ['seg' => count($this->segments) - 1, 'escape' => $escape];
    }

    /** Whether inline Markdown has words to translate outside code, images and Twig. */
    private static function translatable(string $text): bool
    {
        $html = (new InlineConverter())->toHtml($text);
        $visible = preg_replace('/<code data-k="\d+">.*?<\/code>/s', '', $html) ?? $html;
        return self::hasText(strip_tags($visible));
    }

    private function rawSegment(string $quote, string $line, string $newline): void
    {
        preg_match('/^(\s*)(.*?)(\s*)$/s', $line, $m);
        $this->literal($quote . $m[1]);
        $this->segments[] = ['html' => $m[2], 'protected' => [], 'lineBreak' => '', 'tag' => 'div', 'raw' => true];
        $this->parts[] = ['seg' => count($this->segments) - 1, 'escape' => false];
        $this->literal($m[3] . $newline);
    }

    private function table(string $quote, string $line, string $newline): void
    {
        preg_match('/^(\s*\|)(.*)\|(\s*)$/', $line, $m);
        $cells = preg_split('/(?<!\\\\)\|/', $m[2]) ?: [];
        $this->literal($quote . $m[1]);
        foreach ($cells as $i => $cell) {
            preg_match('/^(\s*)(.*?)(\s*)$/s', $cell, $c);
            $this->literal($c[1]);
            if (self::translatable($c[2])) {
                $this->inline($c[2], 'p', '<br>', false);
            } else {
                $this->literal($c[2]);
            }
            $this->literal($c[3] . '|');
        }
        $this->literal($m[3] . $newline);
    }

    private function literal(string $text): void
    {
        if ($text === '') {
            return;
        }
        $last = count($this->parts) - 1;
        if ($last >= 0 && is_string($this->parts[$last])) {
            $this->parts[$last] .= $text;
            return;
        }
        $this->parts[] = $text;
    }

    public static function hasText(string $text): bool
    {
        return preg_match('/\p{L}/u', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) === 1;
    }

    private static function escapeBlockStart(string $text): string
    {
        if (preg_match('/^(#{1,6}\s|>|[-+]\s|\d+[.)]\s|\|)/', $text)) {
            return '\\' . $text;
        }
        return $text;
    }
}
