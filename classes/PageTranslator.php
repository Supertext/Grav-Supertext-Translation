<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation;

use Grav\Plugin\SupertextTranslation\Api\SupertextClient;
use Grav\Plugin\SupertextTranslation\Api\SupertextException;
use Grav\Plugin\SupertextTranslation\Markdown\MarkdownDocument;
use Supertext\GravTranslation\Sdk\Files\FileSegment;
use Supertext\GravTranslation\Sdk\V1\HtmlDocument;
use Supertext\GravTranslation\Sdk\V1\Segment;
use Supertext\GravTranslation\Sdk\V1\SupertextException as SdkException;

/**
 * Translates one Grav page folder into other languages.
 *
 * Grav keeps each language in its own file next to the source:
 * default.en.md → default.de.md, default.fr.md. A translation written by this
 * plugin carries a `supertext` block in its front matter with a fingerprint of
 * what was written. That is how the plugin knows whether an editor has changed
 * the translation since (then it is only replaced on request) and whether the
 * source has changed since (then the translation is outdated).
 */
final class PageTranslator
{
    public const STATE_MISSING = 'missing';     // no file for this language yet
    public const STATE_CURRENT = 'current';     // our translation, untouched, source unchanged
    public const STATE_OUTDATED = 'outdated';   // our translation, untouched, source changed since
    public const STATE_EDITED = 'edited';       // our translation, changed by an editor since
    public const STATE_MANUAL = 'manual';       // written by someone else (no Supertext fingerprint)

    /** Front matter of an existing translation that belongs to that language and is kept on update. */
    private const KEEP_IN_TRANSLATION = ['published', 'slug', 'routes', 'visible', 'publish_date', 'unpublish_date'];

    /** @var callable(): string */
    private $now;

    public function __construct(
        private readonly SupertextClient $client,
        private readonly Settings $settings,
        ?callable $now = null,
    ) {
        $this->now = $now ?? static fn(): string => gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param list<string> $targets Grav language codes
     * @return array{source: array{language: string, file: string}, languages: array<string, array{state: string, file: string, translated: ?string}>}
     */
    public function status(string $folder, string $template, string $source, array $targets): array
    {
        $sourceFile = $this->sourceFile($folder, $template, $source);
        $sourceHash = $sourceFile !== null ? $this->fingerprint(PageFile::read($sourceFile)) : '';

        $languages = [];
        foreach ($targets as $lang) {
            if ($lang === $source) {
                continue;
            }
            $file = $this->targetFile($folder, $template, $lang);
            $languages[$lang] = [
                'state' => $this->state($file, $sourceHash),
                'file' => basename($file),
                'translated' => is_file($file) ? $this->stamp(PageFile::read($file))['translated'] ?? null : null,
            ];
        }
        return [
            'source' => ['language' => $source, 'file' => $sourceFile !== null ? basename($sourceFile) : ''],
            'languages' => $languages,
        ];
    }

    /**
     * @param list<string> $targets Grav language codes
     * @return array<string, array{result: string, state: string, message: string, file: string, reason?: string, args?: list<string|int>, detail?: string, status?: int}>
     *   result: created | updated | skipped | error; status: Supertext's HTTP status for an error answer
     */
    public function translate(string $folder, string $template, string $source, array $targets, bool $overwrite = false): array
    {
        $sourceFile = $this->sourceFile($folder, $template, $source);
        if ($sourceFile === null) {
            throw SupertextException::because('no_source', 'This page has no %s version to translate from.', [strtoupper($source)]);
        }
        $sourcePage = PageFile::read($sourceFile);
        $sourceHash = $this->fingerprint($sourcePage);

        ['document' => $document, 'segments' => $segments, 'fieldIds' => $fieldIds, 'bodyOffset' => $bodyOffset] = $this->collect($sourcePage);
        if ($segments === []) {
            throw SupertextException::because('no_text', 'This page has no text to translate.');
        }
        $html = HtmlDocument::build($segments);
        try {
            $sourceCode = $this->settings->sourceCode($source);
        } catch (SdkException $e) {
            throw SupertextClient::fromSdk($e); // no Supertext language for the source: nothing is sent
        }

        $results = [];
        $jobs = [];
        foreach ($targets as $lang) {
            if ($lang === $source) {
                continue;
            }
            $file = $this->targetFile($folder, $template, $lang);
            $state = $this->state($file, $sourceHash);
            if (!$overwrite && in_array($state, [self::STATE_EDITED, self::STATE_MANUAL], true)) {
                $results[$lang] = [
                    'result' => 'skipped',
                    'state' => $state,
                    'file' => basename($file),
                    'message' => $state === self::STATE_EDITED
                        ? 'The translation was edited after it was translated. It was kept; confirm to replace it.'
                        : 'A translation already exists that was not made with Supertext. It was kept; confirm to replace it.',
                    'reason' => $state === self::STATE_EDITED ? 'kept_edited' : 'kept_manual',
                ];
                continue;
            }
            try {
                $target = $this->settings->targetCode($lang);
            } catch (SdkException $e) {
                // Not mapped: this language is skipped, the others are translated.
                $results[$lang] = ['result' => 'error', 'state' => $state, 'file' => basename($file)] + self::error(SupertextClient::fromSdk($e));
                continue;
            }
            $results[$lang] = ['result' => 'pending', 'state' => $state, 'file' => basename($file), 'message' => ''];
            $jobs[$lang] = [
                'html' => $html,
                'target' => $target,
                'source' => $sourceCode,
                'politeness' => $this->settings->politenessFor($target),
                // Translation review on for this language: through Supertext's review service.
                'review' => $this->settings->reviewFor($lang),
            ];
        }

        foreach ($this->client->translateMany($jobs) as $lang => $translated) {
            $file = $this->targetFile($folder, $template, $lang);
            if ($translated instanceof SupertextException) {
                $results[$lang] = ['result' => 'error', 'state' => $results[$lang]['state'], 'file' => basename($file)] + self::error($translated);
                continue;
            }
            try {
                // Segments missing from the answer stay in the source language.
                $parsed = array_filter(HtmlDocument::parse((string)$translated, $segments), static fn(?string $t): bool => $t !== null);
                $existed = is_file($file);
                $page = $this->buildTranslation($sourcePage, $existed ? PageFile::read($file) : null, $document, $parsed, $fieldIds, $bodyOffset, $jobs[$lang]['target'], $sourceHash);
                $page->write($file);
                $results[$lang] = [
                    'result' => $existed ? 'updated' : 'created',
                    'state' => self::STATE_CURRENT,
                    'file' => basename($file),
                    'message' => count($parsed) < count($segments)
                        ? sprintf('%d of %d text blocks came back untranslated and were kept in the source language.', count($segments) - count($parsed), count($segments))
                        : '',
                ] + (count($parsed) < count($segments) ? ['reason' => 'partly_untranslated', 'args' => [count($segments) - count($parsed), count($segments)]] : []);
            } catch (\Throwable $e) {
                $results[$lang] = ['result' => 'error', 'state' => $results[$lang]['state'], 'file' => basename($file)] + self::error($e);
            }
        }

        return $results;
    }

    /**
     * The texts of the page in the source language as segments of a translation file: exactly what
     * translate() sends, keyed `field.<front matter field>` (plain text) and `body.<block>` (HTML).
     * Null when the page has no version in the source language.
     *
     * @return list<FileSegment>|null
     */
    public function fileSegments(string $folder, string $template, string $source): ?array
    {
        $sourceFile = $this->sourceFile($folder, $template, $source);
        if ($sourceFile === null) {
            return null;
        }
        $collected = $this->collect(PageFile::read($sourceFile));
        $out = [];
        foreach ($collected['segments'] as $id => $segment) {
            $out[] = new FileSegment($collected['keys'][$id], $segment->text, $segment->html);
        }
        return $out;
    }

    /**
     * Writes texts imported from a translation file (keyed like fileSegments()) into the language
     * file, with the same rules as translate(): a translation edited by hand or not made with
     * Supertext is only replaced with $overwrite, a new one is unpublished unless configured
     * otherwise, and texts without an imported translation are in the source language.
     *
     * @param array<string, string> $texts
     * @return array{result: string, state: string, message: string, file: string, reason?: string}
     */
    public function writeImported(string $folder, string $template, string $source, string $lang, array $texts, bool $overwrite = false): array
    {
        $sourceFile = $this->sourceFile($folder, $template, $source);
        if ($sourceFile === null) {
            throw SupertextException::because('no_source', 'This page has no %s version to translate from.', [strtoupper($source)]);
        }
        $sourcePage = PageFile::read($sourceFile);
        $sourceHash = $this->fingerprint($sourcePage);
        $file = $this->targetFile($folder, $template, $lang);
        $state = $this->state($file, $sourceHash);
        if (!$overwrite && in_array($state, [self::STATE_EDITED, self::STATE_MANUAL], true)) {
            return [
                'result' => 'skipped',
                'state' => $state,
                'file' => basename($file),
                'message' => $state === self::STATE_EDITED
                    ? 'The translation was edited after it was translated. It was kept; confirm to replace it.'
                    : 'A translation already exists that was not made with Supertext. It was kept; confirm to replace it.',
                'reason' => $state === self::STATE_EDITED ? 'kept_edited' : 'kept_manual',
            ];
        }

        $collected = $this->collect($sourcePage);
        $parsed = [];
        foreach ($collected['keys'] as $id => $key) {
            if (isset($texts[$key]) && trim($texts[$key]) !== '') {
                $parsed[$id] = $texts[$key];
            }
        }
        $existed = is_file($file);
        $page = $this->buildTranslation(
            $sourcePage,
            $existed ? PageFile::read($file) : null,
            $collected['document'],
            $parsed,
            $collected['fieldIds'],
            $collected['bodyOffset'],
            $this->settings->targetCodeOrNull($lang) ?? str_replace('_', '-', $lang),
            $sourceHash,
        );
        $page->write($file);

        return ['result' => $existed ? 'updated' : 'created', 'state' => self::STATE_CURRENT, 'file' => basename($file), 'message' => ''];
    }

    /**
     * What to translate: front matter fields first (plain text), then the body blocks (HTML).
     *
     * @return array{document: MarkdownDocument, segments: list<Segment>, keys: list<string>, fieldIds: array<string, int>, bodyOffset: int}
     */
    private function collect(PageFile $sourcePage): array
    {
        $document = MarkdownDocument::parse($sourcePage->body);
        $segments = [];
        $keys = [];
        $fieldIds = [];
        foreach ($this->settings->headerFields as $field) {
            $value = $sourcePage->get($field);
            if (is_string($value) && MarkdownDocument::hasText($value)) {
                $fieldIds[$field] = count($segments);
                $segments[] = Segment::text($value);
                $keys[] = 'field.' . $field;
            }
        }
        $bodyOffset = count($segments);
        foreach ($document->segments() as $n => $segment) {
            $segments[] = Segment::html($segment['html']);
            $keys[] = 'body.' . $n;
        }

        return ['document' => $document, 'segments' => $segments, 'keys' => $keys, 'fieldIds' => $fieldIds, 'bodyOffset' => $bodyOffset];
    }

    /**
     * The English message of an error, plus its reason, args and detail when it has one,
     * so the admin can show it in the user's language.
     *
     * @return array{message: string, reason?: string, args?: list<string|int>, detail?: string, status?: int}
     */
    private static function error(\Throwable $e): array
    {
        if ($e instanceof SupertextException && $e->reason !== '') {
            return ['message' => $e->getMessage(), 'reason' => $e->reason, 'args' => $e->args, 'detail' => $e->detail]
                + ($e->status !== null ? ['status' => $e->status] : []);
        }

        return ['message' => $e->getMessage()];
    }

    /**
     * @param array<int, string> $parsed translated segments by id (front matter fields as plain text, body as HTML)
     * @param array<string, int> $fieldIds front matter field => segment id
     */
    private function buildTranslation(
        PageFile $source,
        ?PageFile $existing,
        MarkdownDocument $document,
        array $parsed,
        array $fieldIds,
        int $bodyOffset,
        string $targetCode,
        string $sourceHash,
    ): PageFile {
        $header = $source->header;
        unset($header['supertext']);
        if ($existing !== null) {
            foreach (self::KEEP_IN_TRANSLATION as $key) {
                if (array_key_exists($key, $existing->header)) {
                    $header[$key] = $existing->header[$key];
                }
            }
        } elseif (!$this->settings->publishNewTranslations) {
            $header['published'] = false;
        }

        $page = new PageFile($header, '');
        foreach ($fieldIds as $field => $id) {
            if (isset($parsed[$id]) && trim($parsed[$id]) !== '') {
                $page->set($field, $parsed[$id]);
            }
        }

        $body = [];
        foreach ($parsed as $id => $segmentHtml) {
            if ($id >= $bodyOffset) {
                $body[$id - $bodyOffset] = $segmentHtml;
            }
        }
        $page->body = $document->render($body);

        $page->header['supertext'] = [
            'language' => $targetCode,
            'source_hash' => $sourceHash,
            'translation_hash' => $this->fingerprint($page),
            'translated' => ($this->now)(),
        ];
        return $page;
    }

    private function state(string $file, string $sourceHash): string
    {
        if (!is_file($file)) {
            return self::STATE_MISSING;
        }
        $page = PageFile::read($file);
        $stamp = $this->stamp($page);
        if (!isset($stamp['translation_hash'])) {
            return self::STATE_MANUAL;
        }
        if ($stamp['translation_hash'] !== $this->fingerprint($page)) {
            return self::STATE_EDITED;
        }
        return ($stamp['source_hash'] ?? '') === $sourceHash ? self::STATE_CURRENT : self::STATE_OUTDATED;
    }

    /** @return array<string, string> */
    private function stamp(PageFile $page): array
    {
        $stamp = $page->header['supertext'] ?? null;
        return is_array($stamp) ? array_map('strval', array_filter($stamp, 'is_scalar')) : [];
    }

    /** Fingerprint of the translatable content (configured front matter fields and body). */
    public function fingerprint(PageFile $page): string
    {
        $fields = [];
        foreach ($this->settings->headerFields as $field) {
            $value = $page->get($field);
            $fields[$field] = is_scalar($value) ? trim((string)$value) : null;
        }
        $body = str_replace(["\r\n", "\r"], "\n", $page->body);
        $body = trim(implode("\n", array_map('rtrim', explode("\n", $body))));
        return sha1((string)json_encode([$fields, $body], JSON_UNESCAPED_UNICODE));
    }

    public function sourceFile(string $folder, string $template, string $source): ?string
    {
        foreach ([$template . '.' . $source . '.md', $template . '.md'] as $name) {
            if (is_file($folder . '/' . $name)) {
                return $folder . '/' . $name;
            }
        }
        return null;
    }

    public function targetFile(string $folder, string $template, string $lang): string
    {
        return $folder . '/' . $template . '.' . $lang . '.md';
    }
}
