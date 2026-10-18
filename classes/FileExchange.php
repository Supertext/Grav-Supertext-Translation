<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation;

use Supertext\GravTranslation\Sdk\Files\FileJob;
use Supertext\GravTranslation\Sdk\Files\FileSegment;
use Supertext\GravTranslation\Sdk\Files\ImportedSegment;
use Supertext\GravTranslation\Sdk\Files\TranslationFile;
use Supertext\GravTranslation\Sdk\Files\TranslationFileError;
use Supertext\GravTranslation\Sdk\Files\TranslationFiles;

/**
 * "Export for translation" and "Import translation": a page's texts as an XLIFF 1.2 or HTML file
 * (the bundled SDK's `Files\TranslationFiles`), translated outside the API, and the translated file
 * written back like an API translation.
 *
 * One job = one page from the source language into one site language. The segments are those
 * PageTranslator::translate() sends (PageTranslator::fileSegments(): `field.<name>`, `body.<n>`).
 * Reference: `page:<route>:<Grav language>`, e.g. `page:/blog/hello:fr`.
 *
 * No Grav classes here (tests/FileExchangeTest.php); TranslationController loads and writes the pages.
 */
final class FileExchange
{
    /** The `plugin` of every job: this repository's name. */
    public const PLUGIN = 'Grav-Supertext-Translation';

    /** Uploads larger than this are refused before they are read. */
    public const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    public const FORMAT_XLIFF = 'xliff';
    public const FORMAT_HTML = 'html';

    /** Import errors, shown as ERRORS.FILE_<CODE>; the TranslationFileError codes come from read(). */
    public const OTHER_SYSTEM = 'other_system';
    public const NOT_FOUND = 'not_found';
    public const NO_LANGUAGE = 'no_language';
    public const FORBIDDEN = 'forbidden';

    private const REFERENCE = '~^page:(/[^:\s]*):([A-Za-z0-9_-]+)$~';

    public static function reference(string $route, string $language): string
    {
        return 'page:/' . trim($route, '/') . ':' . $language;
    }

    /** @return array{route: string, language: string}|null */
    public static function parseReference(string $reference): ?array
    {
        if (!preg_match(self::REFERENCE, $reference, $m)) {
            return null;
        }

        return ['route' => $m[1], 'language' => $m[2]];
    }

    /** A Grav language as a language tag (`de_CH` → `de-CH`), for languages without a Supertext code. */
    public static function fallbackCode(string $language): string
    {
        return str_replace('_', '-', $language);
    }

    /**
     * The file for one page and the given languages (XLIFF: one or more, HTML: exactly one), or
     * null when the page has nothing to translate.
     *
     * @param list<array{language: string, code: string}> $targets Grav language and the Supertext code (or fallback code) it is exported as
     * @param list<FileSegment> $segments
     * @return array{content: string, filename: string, contentType: string}|null
     */
    public static function export(string $route, string $sourceCode, array $targets, array $segments, string $format): ?array
    {
        if ($targets === []) {
            throw new \InvalidArgumentException('Choose at least one language.');
        }
        if ($format === self::FORMAT_HTML && count($targets) !== 1) {
            throw new \InvalidArgumentException('An HTML file holds exactly one language.');
        }
        $segments = array_values(array_filter($segments, static fn(FileSegment $s): bool => trim($s->text) !== ''));
        if ($segments === []) {
            return null;
        }

        $jobs = [];
        foreach ($targets as $target) {
            $jobs[] = new FileJob(self::PLUGIN, self::reference($route, $target['language']), $sourceCode, $target['code'], $segments);
        }

        $slug = trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($route, '/')), '-');
        $name = 'grav-' . ($slug !== '' ? $slug : 'home') . (count($targets) === 1 ? '-' . self::fallbackCode($targets[0]['language']) : '');
        if ($format === self::FORMAT_HTML) {
            return ['content' => TranslationFiles::exportHtml($jobs[0]), 'filename' => $name . '.html', 'contentType' => 'text/html; charset=utf-8'];
        }

        return ['content' => TranslationFiles::exportXliff($jobs), 'filename' => $name . '.xlf', 'contentType' => 'application/x-xliff+xml'];
    }

    /**
     * @return list<TranslationFile>
     * @throws TranslationFileError invalid_file, missing_metadata, too_large
     */
    public static function read(string $content): array
    {
        return TranslationFiles::read($content);
    }

    /**
     * Imports one job of a file.
     *
     * `$open(route, language)` loads the page with the user's permissions and returns either
     * `['error' => <code>, 'args' => [...]]` or `['title' => …, 'segments' => list<FileSegment>,
     * 'write' => callable(array<string, string> key => text): array (PageTranslator::writeImported()'s result)]`.
     *
     * @param callable(string, string): array<string, mixed> $open
     * @return array{route: ?string, language: string, title: string, error: ?string, args: list<string>, counts: array<string, int>, imported: int, write: ?array<string, mixed>}
     */
    public static function import(TranslationFile $file, bool $includeChanged, callable $open): array
    {
        $result = [
            'route' => null,
            'language' => $file->targetLanguage,
            'title' => $file->reference,
            'error' => null,
            'args' => [],
            'counts' => array_fill_keys([ImportedSegment::OK, ImportedSegment::SOURCE_CHANGED, ImportedSegment::UNTRANSLATED, ImportedSegment::INVALID_MARKUP, ImportedSegment::MISSING], 0),
            'imported' => 0,
            'write' => null,
        ];

        if ($file->plugin !== self::PLUGIN) {
            return ['error' => self::OTHER_SYSTEM, 'args' => [$file->plugin]] + $result;
        }
        $reference = self::parseReference($file->reference);
        if ($reference === null) {
            return ['error' => self::NOT_FOUND] + $result;
        }
        $result['route'] = $reference['route'];
        $result['language'] = $reference['language'];

        $opened = $open($reference['route'], $reference['language']);
        $result['title'] = (string)($opened['title'] ?? $result['title']);
        if (isset($opened['error'])) {
            return ['error' => (string)$opened['error'], 'args' => array_values(array_map('strval', (array)($opened['args'] ?? [])))] + $result;
        }

        $translations = [];
        foreach (TranslationFiles::importTranslations($file, $opened['segments'] ?? []) as $segment) {
            $result['counts'][$segment->status]++;
            if ($segment->text !== null && ($segment->status === ImportedSegment::OK || ($includeChanged && $segment->status === ImportedSegment::SOURCE_CHANGED))) {
                $translations[$segment->key] = $segment->text;
            }
        }
        if ($translations !== []) {
            $result['write'] = ($opened['write'])($translations);
            $result['imported'] = ($result['write']['result'] ?? '') === 'skipped' ? 0 : count($translations);
        }

        return $result;
    }
}
