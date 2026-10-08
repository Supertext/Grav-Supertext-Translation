<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Controller;

use Grav\Common\Language\LanguageCodes;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\SupertextTranslation\Api\SupertextClient;
use Grav\Plugin\SupertextTranslation\Api\SupertextException;
use Grav\Plugin\SupertextTranslation\Messages;
use Grav\Plugin\SupertextTranslation\PageTranslator;
use Grav\Plugin\SupertextTranslation\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * API routes behind the Supertext panel in the admin2 page editor:
 *
 *   GET  /api/v1/supertext/status?route=/about      languages and their translation state
 *   POST /api/v1/supertext/translate                {route, languages: [de, fr], overwrite: false}
 *
 * Both need the same rights as editing the page (api.pages.write, or a page-level grant).
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

        $status = $this->translator($settings)->status($page->path() ?? '', $page->template(), $source, $targets);
        $languages = [];
        foreach ($status['languages'] as $code => $info) {
            $languages[] = $info + [
                'code' => $code,
                'name' => $this->languageName($code),
                'supertext_code' => $settings->supertextCode($code),
            ];
        }

        return ApiResponse::create([
            'route' => $page->rawRoute(),
            'title' => $page->title(),
            'multilanguage' => count($targets) > 1,
            'api_key_configured' => $settings->apiKey !== '',
            'translated_fields' => $settings->headerFields,
            'source' => $status['source'] + ['name' => $this->languageName($source)],
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
        try {
            $results = $this->translator($settings)->translate(
                $page->path() ?? '',
                $page->template(),
                $source,
                $requested,
                filter_var($body['overwrite'] ?? false, FILTER_VALIDATE_BOOLEAN),
            );
        } catch (SupertextException $e) {
            throw new ValidationException($messages->error($e));
        }

        $written = false;
        $out = [];
        foreach ($results as $code => $result) {
            $written = $written || in_array($result['result'], ['created', 'updated'], true);
            if ($result['result'] === 'error') {
                $this->grav['log']->warning(sprintf('Supertext: translating %s into %s failed: %s', $page->rawRoute(), $code, $result['message']));
            }
            if (($result['reason'] ?? '') !== '') {
                $result['message'] = $messages->reason($result['reason'], $result['args'] ?? [], $result['detail'] ?? '', $result['message']);
            }
            unset($result['reason'], $result['args'], $result['detail']);
            $out[] = $result + ['code' => $code, 'name' => $this->languageName($code)];
        }
        if ($written) {
            $this->clearCache();
        }

        $route = $page->rawRoute();
        return ApiResponse::create(
            ['route' => $route, 'results' => $out],
            200,
            $written ? $this->invalidationHeaders(['pages:update:' . $route, 'pages:list']) : [],
        );
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
        $key = getenv('SUPERTEXT_API_KEY');
        $url = getenv('SUPERTEXT_API_URL');
        return Settings::fromArray(
            (array)$this->config->get('plugins.supertext-translation', []),
            $key === false ? null : $key,
            $url === false ? null : $url,
        );
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

    private function translator(Settings $settings): PageTranslator
    {
        $client = new SupertextClient(
            $settings->apiKey,
            baseUrl: $settings->apiUrl,
            pollInterval: $settings->pollInterval,
            pollTimeout: $settings->pollTimeout,
        );
        return new PageTranslator($client, $settings);
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
