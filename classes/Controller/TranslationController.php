<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Controller;

use Grav\Common\Language\LanguageCodes;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Framework\Psr7\Response;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\SupertextTranslation\Api\SupertextClient;
use Grav\Plugin\SupertextTranslation\Api\SupertextException;
use Grav\Plugin\SupertextTranslation\FileExchange;
use Grav\Plugin\SupertextTranslation\Messages;
use Grav\Plugin\SupertextTranslation\PageTranslator;
use Grav\Plugin\SupertextTranslation\PluginVersion;
use Grav\Plugin\SupertextTranslation\Reporting\GravReporting;
use Grav\Plugin\SupertextTranslation\Reporting\Reporting;
use Grav\Plugin\SupertextTranslation\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Supertext\GravTranslation\Sdk\Files\TranslationFileError;
use Supertext\GravTranslation\Sdk\V1\Client;
use Supertext\GravTranslation\Sdk\V1\Languages;

/**
 * API routes behind the Supertext panel in the admin2 page editor:
 *
 *   GET  /api/v1/supertext/status?route=/about      languages and their translation state
 *   POST /api/v1/supertext/translate                {route, languages: [de, fr], overwrite: false}
 *   POST /api/v1/supertext/export                   {route, languages: [de, fr], format: xliff|html} → the file (attachment)
 *   POST /api/v1/supertext/import                   multipart: file, include_changed, overwrite → {results}
 *
 * All need the same rights as editing the page (api.pages.write, or a page-level grant); an import
 * checks them for every page in the file. Export/import: see FileExchange (no Supertext API calls).
 * After the response, both let the Supertext reporter look for an update or a changed API key
 * (Reporting::ON_REQUEST); a translation that Supertext refused with 401/403 marks the key as
 * rejected (Reporting::AUTHENTICATION_FAILED).
 */
final class TranslationController extends AbstractApiController
{
    private const PERMISSION = 'api.pages.write';

    private ?Messages $supertextMessages = null;

    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $page = $this->page($request, (string)($request->getQueryParams()['route'] ?? ''));
        $settings = $this->settings();
        [$source, $targets] = $this->languages($request, $settings);

        $status = $this->translator($settings, $request)->status($page->path() ?? '', $page->template(), $source, $targets);
        $languages = [];
        foreach ($status['languages'] as $code => $info) {
            $target = $settings->targetCodeOrNull($code);
            $languages[] = $info + [
                'code' => $code,
                'name' => $this->languageName($code),
                // null: no Supertext language mapped yet, the panel can't translate into it
                'supertext_code' => $target,
                'politeness_supported' => $target !== null ? Languages::supportsPoliteness($target) : null,
            ];
        }
        GravReporting::create($this->grav, $settings)->runLater(Reporting::ON_REQUEST, $settings->apiKey);

        return ApiResponse::create([
            'route' => $page->rawRoute(),
            'title' => $page->title(),
            'multilanguage' => count($targets) > 1,
            'api_key_configured' => $settings->apiKey !== '',
            'translated_fields' => $settings->headerFields,
            'source' => $status['source'] + ['name' => $this->languageName($source), 'supertext_code' => $settings->sourceCodeOrNull($source)],
            'languages' => $languages,
        ]);
    }

    public function translate(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getRequestBody($request);
        $page = $this->page($request, (string)($body['route'] ?? ''));
        $settings = $this->settings();
        [$source, $targets] = $this->languages($request, $settings);
        $messages = $this->messages($request);

        $requested = array_values(array_unique(array_map('strval', (array)($body['languages'] ?? []))));
        $unknown = array_diff($requested, $targets);
        if ($requested === [] || $unknown !== []) {
            throw new ValidationException($requested === []
                ? $messages->text('ERRORS.CHOOSE_LANGUAGE', [], 'Choose at least one language.')
                : $messages->text('ERRORS.UNKNOWN_LANGUAGES', [implode(', ', $unknown)], 'Not a language of this site: ' . implode(', ', $unknown)));
        }
        $requested = array_values(array_diff($requested, [$source]));
        if ($settings->apiKey === '') {
            throw new ValidationException($messages->reason('no_api_key', [], '', 'No Supertext API key is configured. An administrator can add it in the plugin settings. No Supertext account yet? Create one at https://www.supertext.com/person/en/account/signin. Generate your API key at https://www.supertext.com/en/integrations/api (requires the Admin role).'));
        }

        @set_time_limit($settings->pollTimeout + 120);
        $reporting = GravReporting::create($this->grav, $settings);
        try {
            $results = $this->translator($settings, $request)->translate(
                $page->path() ?? '',
                $page->template(),
                $source,
                $requested,
                filter_var($body['overwrite'] ?? false, FILTER_VALIDATE_BOOLEAN),
            );
        } catch (SupertextException $e) {
            $reporting->runLater(Reporting::ON_REQUEST, $settings->apiKey);
            throw new ValidationException($messages->error($e));
        }

        $rejected = null; // HTTP status of a translation Supertext refused because of the key
        $written = false;
        $out = [];
        foreach ($results as $code => $result) {
            if (in_array($result['status'] ?? null, [401, 403], true)) {
                $rejected = $result['status'];
            }
            $written = $written || in_array($result['result'], ['created', 'updated'], true);
            if ($result['result'] === 'error') {
                $this->grav['log']->warning(sprintf('Supertext: translating %s into %s failed: %s', $page->rawRoute(), $code, $result['message']));
            }
            if (($result['reason'] ?? '') !== '') {
                $result['message'] = $messages->reason($result['reason'], $result['args'] ?? [], $result['detail'] ?? '', $result['message']);
            }
            unset($result['reason'], $result['args'], $result['detail'], $result['status']);
            $out[] = $result + ['code' => $code, 'name' => $this->languageName($code)];
        }
        if ($written) {
            $this->clearCache();
        }
        $rejected !== null
            ? $reporting->runLater(Reporting::AUTHENTICATION_FAILED, $settings->apiKey, $rejected)
            : $reporting->runLater(Reporting::ON_REQUEST, $settings->apiKey);

        $route = $page->rawRoute();
        return ApiResponse::create(
            ['route' => $route, 'results' => $out],
            200,
            $written ? $this->invalidationHeaders(['pages:update:' . $route, 'pages:list']) : [],
        );
    }

    /** Export for translation: the page's texts as an XLIFF 1.2 or HTML file. */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getRequestBody($request);
        $page = $this->page($request, (string)($body['route'] ?? ''));
        $settings = $this->settings();
        [$source, $all] = $this->languages($request, $settings);
        $messages = $this->messages($request);
        $format = ($body['format'] ?? '') === FileExchange::FORMAT_HTML ? FileExchange::FORMAT_HTML : FileExchange::FORMAT_XLIFF;

        $requested = array_values(array_diff(array_unique(array_map('strval', (array)($body['languages'] ?? []))), [$source]));
        $unknown = array_diff($requested, $all);
        if ($requested === [] || $unknown !== []) {
            throw new ValidationException($requested === []
                ? $messages->text('ERRORS.CHOOSE_LANGUAGE', [], 'Choose at least one language.')
                : $messages->text('ERRORS.UNKNOWN_LANGUAGES', [implode(', ', $unknown)], 'Not a language of this site: ' . implode(', ', $unknown)));
        }
        if ($format === FileExchange::FORMAT_HTML && count($requested) !== 1) {
            throw new ValidationException($messages->text('ERRORS.FILE_HTML_ONE_LANGUAGE', [], 'An HTML file holds exactly one language. Choose one language or XLIFF.'));
        }

        $segments = $this->translator($settings, $request)->fileSegments($page->path() ?? '', $page->template(), $source);
        if ($segments === null) {
            throw new ValidationException($messages->reason('no_source', [strtoupper($source)], '', sprintf('This page has no %s version to translate from.', strtoupper($source))));
        }
        $targets = array_map(static fn(string $lang): array => [
            'language' => $lang,
            'code' => $settings->targetCodeOrNull($lang) ?? FileExchange::fallbackCode($lang),
        ], $requested);

        try {
            $file = FileExchange::export($page->rawRoute(), $settings->sourceCodeOrNull($source) ?? FileExchange::fallbackCode($source), $targets, $segments, $format);
        } catch (TranslationFileError) {
            $file = null; // invalid_job: nothing left to translate
        }
        if ($file === null) {
            throw new ValidationException($messages->text('ERRORS.FILE_NOTHING', [], 'Nothing to translate.'));
        }

        return new Response(200, [
            'Content-Type' => $file['contentType'],
            'Content-Disposition' => 'attachment; filename="' . $file['filename'] . '"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $file['content']);
    }

    /** Import translation: an uploaded XLIFF or HTML file, written like a Supertext translation. */
    public function import(ServerRequestInterface $request): ResponseInterface
    {
        $messages = $this->messages($request);
        $upload = $request->getUploadedFiles()['file'] ?? null;
        if (!$upload instanceof UploadedFileInterface || $upload->getError() === UPLOAD_ERR_NO_FILE) {
            throw new ValidationException($messages->text('ERRORS.FILE_NO_UPLOAD', [], 'Please choose a file.'));
        }
        if (in_array($upload->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int)$upload->getSize() > FileExchange::MAX_UPLOAD_BYTES) {
            throw new ValidationException($messages->text('ERRORS.FILE_TOO_LARGE', [], 'The file is too large (at most 10 MB).'));
        }
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException($messages->text('ERRORS.FILE_NO_UPLOAD', [], 'Please choose a file.'));
        }
        // Read in memory only, never stored.
        $content = $upload->getStream()->read(FileExchange::MAX_UPLOAD_BYTES + 1);

        $form = (array)($request->getParsedBody() ?? []);
        $includeChanged = filter_var($form['include_changed'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $overwrite = filter_var($form['overwrite'] ?? false, FILTER_VALIDATE_BOOLEAN);

        try {
            $jobs = FileExchange::read($content);
        } catch (TranslationFileError $e) {
            $key = 'ERRORS.FILE_' . strtoupper($e->errorCode); // FILE_INVALID_FILE, FILE_MISSING_METADATA, FILE_TOO_LARGE
            throw new ValidationException($messages->text($key, [], $e->getMessage()));
        }

        $settings = $this->settings();
        [$source, $all] = $this->languages($request, $settings);
        $translator = $this->translator($settings, $request);
        $written = [];
        $out = [];
        foreach ($jobs as $job) {
            $open = function (string $route, string $lang) use ($request, $source, $all, $translator, $overwrite, &$written): array {
                try {
                    $page = $this->page($request, $route);
                } catch (NotFoundException|ValidationException) {
                    return ['error' => FileExchange::NOT_FOUND];
                } catch (\Throwable) {
                    return ['error' => FileExchange::FORBIDDEN];
                }
                $info = ['title' => (string)$page->title()];
                if ($lang === $source || !in_array($lang, $all, true)) {
                    return $info + ['error' => FileExchange::NO_LANGUAGE, 'args' => [$lang]];
                }
                $segments = $translator->fileSegments($page->path() ?? '', $page->template(), $source);
                if ($segments === null) {
                    return $info + ['error' => FileExchange::NOT_FOUND];
                }

                return $info + [
                    'segments' => $segments,
                    'write' => function (array $texts) use ($translator, $page, $source, $lang, $overwrite, &$written): array {
                        $result = $translator->writeImported($page->path() ?? '', $page->template(), $source, $lang, $texts, $overwrite);
                        if ($result['result'] !== 'skipped') {
                            $written[] = 'pages:update:' . $page->rawRoute();
                        }
                        return $result;
                    },
                ];
            };

            try {
                $result = FileExchange::import($job, $includeChanged, $open);
            } catch (\Throwable $e) {
                $this->grav['log']->warning(sprintf('Supertext: importing %s failed: %s', $job->reference, $e->getMessage()));
                $result = ['route' => null, 'language' => $job->targetLanguage, 'title' => $job->reference, 'error' => 'write_failed', 'args' => [], 'counts' => [], 'imported' => 0, 'write' => null];
                $result['message'] = $messages->error($e);
            }
            $out[] = $this->importResult($result, $messages);
        }
        if ($written !== []) {
            $this->clearCache();
        }
        GravReporting::create($this->grav, $settings)->runLater(Reporting::ON_REQUEST, $settings->apiKey);

        return ApiResponse::create(
            ['results' => $out],
            200,
            $written !== [] ? $this->invalidationHeaders([...array_unique($written), 'pages:list']) : [],
        );
    }

    /**
     * One job's result for the panel: the counts, plus `message` in the user's language for an error
     * or a kept translation.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function importResult(array $result, Messages $messages): array
    {
        $out = [
            'route' => $result['route'],
            'title' => $result['title'],
            'code' => $result['language'],
            'name' => $this->languageName((string)$result['language']),
            'counts' => $result['counts'],
            'imported' => $result['imported'],
            'result' => $result['error'] !== null ? 'error' : (string)($result['write']['result'] ?? 'skipped'),
            'message' => (string)($result['message'] ?? ''),
        ];
        if ($result['error'] !== null && $out['message'] === '') {
            $english = [
                FileExchange::OTHER_SYSTEM => 'This file belongs to another system (%s).',
                FileExchange::NOT_FOUND => 'The page this file was exported from no longer exists.',
                FileExchange::NO_LANGUAGE => 'The site has no language %s.',
                FileExchange::FORBIDDEN => 'You are not allowed to edit this page.',
            ][$result['error']] ?? (string)$result['error'];
            $key = 'ERRORS.FILE_' . strtoupper((string)$result['error']); // FILE_OTHER_SYSTEM, FILE_NOT_FOUND, FILE_NO_LANGUAGE, FILE_FORBIDDEN
            $out['message'] = $messages->text($key, $result['args'], vsprintf($english, $result['args'] ?: ['']));
        } elseif (($result['write']['reason'] ?? '') !== '') {
            $out['message'] = $messages->reason($result['write']['reason'], [], '', (string)$result['write']['message']);
        }

        return $out;
    }

    private function page(ServerRequestInterface $request, string $route): PageInterface
    {
        $route = '/' . trim($route, '/');
        if ($route === '/') {
            throw new ValidationException($this->messages($request)->text('ERRORS.ROUTE_MISSING', [], 'The page route is missing.'));
        }
        $page = $this->resolvePageByRoute($route);
        if (!$page instanceof PageInterface || !$page->path()) {
            throw new NotFoundException($this->messages($request)->text('ERRORS.PAGE_NOT_FOUND', [$route], 'Page not found: ' . $route));
        }
        $this->authorizePageAction($request, $page, 'update', self::PERMISSION);
        return $page;
    }

    private function settings(): Settings
    {
        return Settings::fromConfig((array)$this->config->get('plugins.supertext-translation', []));
    }

    /** @return array{0: string, 1: list<string>} source language and all site languages */
    private function languages(ServerRequestInterface $request, Settings $settings): array
    {
        $language = $this->grav['language'];
        $all = array_values(array_map('strval', (array)$language->getLanguages()));
        if (count($all) < 2) {
            throw new ValidationException($this->messages($request)->text('ERRORS.ONE_LANGUAGE', [], 'This site has only one language. Add languages under Configuration → System → Languages first.'));
        }
        $source = $settings->sourceLanguage !== '' && in_array($settings->sourceLanguage, $all, true)
            ? $settings->sourceLanguage
            : (string)($language->getDefault() ?: $all[0]);
        return [$source, $all];
    }

    private function messages(ServerRequestInterface $request): Messages
    {
        if ($this->supertextMessages === null) {
            try {
                $user = $this->getUser($request);
            } catch (\Throwable) {
                $user = null;
            }
            $this->supertextMessages = Messages::forUser($this->grav, $user);
        }

        return $this->supertextMessages;
    }

    private function translator(Settings $settings, ServerRequestInterface $request): PageTranslator
    {
        try {
            $sdk = new Client(
                $settings->apiKey,
                baseUrl: $settings->apiUrl,
                pollInterval: (float)$settings->pollInterval,
                timeout: (float)$settings->pollTimeout,
                userAgent: 'Grav-Supertext-Translation/' . PluginVersion::get(),
            );
        } catch (\LogicException) {
            // The SDK's built-in transport needs ext-curl.
            throw new ValidationException($this->messages($request)->reason('no_curl', [], '', 'The PHP curl extension is required to reach Supertext.'));
        }

        return new PageTranslator(new SupertextClient($sdk), $settings);
    }

    private function languageName(string $code): string
    {
        $name = LanguageCodes::getNativeName($code) ?: LanguageCodes::getName($code);
        return is_string($name) && $name !== '' ? $name : strtoupper($code);
    }

    private function clearCache(): void
    {
        try {
            \Grav\Common\Cache::clearCache('cache-only');
        } catch (\Throwable) {
            // Grav notices changed page files on its own; the cache clear only makes it immediate.
        }
    }
}
