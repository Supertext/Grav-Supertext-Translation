<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation;

use Grav\Plugin\SupertextTranslation\Api\SupertextClient;
use Supertext\GravTranslation\Sdk\V1\Languages;
use Supertext\GravTranslation\Sdk\V1\Politeness;
use Supertext\GravTranslation\Sdk\V1\Protocol;
use Supertext\GravTranslation\Sdk\V1\SupertextException as SdkException;

/** Plugin settings (user/config/plugins/supertext-translation.yaml), with defaults. */
final class Settings
{
    /**
     * @param array<string, string> $languageCodes Grav language code => Supertext language code
     * @param list<string> $headerFields front matter fields to translate (dot paths)
     * @param bool|null $versionReports "Send version information to Supertext" (null: not set, on)
     * @param list<string> $reviewLanguages Grav codes (lower case) with *Translation review* on
     * @param string $reviewUrl Supertext's review service for those languages ('' = review off everywhere)
     */
    public function __construct(
        public readonly string $apiKey = '',
        public readonly string $apiUrl = Protocol::LIVE_BASE_URL,
        public readonly string $sourceLanguage = '',
        public readonly array $languageCodes = [],
        public readonly string $politeness = 'default',
        public readonly array $headerFields = ['title', 'menu', 'metadata.description'],
        public readonly bool $publishNewTranslations = false,
        public readonly int $pollInterval = 2,
        public readonly int $pollTimeout = 300,
        public readonly ?bool $versionReports = null,
        public readonly array $reviewLanguages = [],
        public readonly string $reviewUrl = SupertextClient::REVIEW_URL,
    ) {}

    /**
     * The settings with SUPERTEXT_API_KEY, SUPERTEXT_API_URL and SUPERTEXT_REVIEW_URL from the environment.
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        $key = getenv('SUPERTEXT_API_KEY');
        $url = getenv('SUPERTEXT_API_URL');
        $reviewUrl = getenv('SUPERTEXT_REVIEW_URL');

        return self::fromArray($config, $key === false ? null : $key, $url === false ? null : $url, $reviewUrl === false ? null : $reviewUrl);
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config, ?string $environmentKey = null, ?string $environmentUrl = null, ?string $environmentReviewUrl = null): self
    {
        $apiKey = self::normalizeKey((string)($config['api_key'] ?? ''));
        if ($apiKey === '' && $environmentKey !== null) {
            $apiKey = self::normalizeKey($environmentKey);
        }

        $codes = [];
        $review = [];
        foreach (self::mappingEntries($config['language_codes'] ?? []) as $key => $entry) {
            if ($entry['code'] !== '') {
                $codes[strtolower($key)] = $entry['code'];
            }
            if ($entry['review']) {
                $review[] = strtolower($key);
            }
        }

        // review_url: SUPERTEXT_REVIEW_URL (if not empty) wins; a missing setting is Supertext's
        // review service, an empty one turns review off for every language.
        $reviewUrl = trim((string)$environmentReviewUrl);
        if ($reviewUrl === '') {
            $reviewUrl = array_key_exists('review_url', $config) && $config['review_url'] !== null
                ? trim((string)$config['review_url'])
                : SupertextClient::REVIEW_URL;
        }

        $fields = array_values(array_filter(array_map(
            static fn($f): string => trim((string)$f),
            (array)($config['header_fields'] ?? ['title', 'menu', 'metadata.description']),
        ), static fn(string $f): bool => $f !== ''));

        $politeness = (string)($config['politeness'] ?? 'default');

        return new self(
            apiKey: $apiKey,
            // SUPERTEXT_API_URL (testing only) wins over the setting, e.g. for a local stand-in API.
            apiUrl: trim((string)$environmentUrl) ?: trim((string)($config['api_url'] ?? '')) ?: Protocol::LIVE_BASE_URL,
            sourceLanguage: strtolower(trim((string)($config['source_language'] ?? ''))),
            languageCodes: $codes,
            politeness: in_array($politeness, ['default', 'more', 'less'], true) ? $politeness : 'default',
            headerFields: $fields,
            publishNewTranslations: (bool)($config['publish_new_translations'] ?? false),
            pollInterval: max(1, (int)($config['poll_interval'] ?? 2)),
            pollTimeout: max(10, (int)($config['poll_timeout'] ?? 300)),
            versionReports: array_key_exists('version_reports', $config) && $config['version_reports'] !== null && $config['version_reports'] !== ''
                ? filter_var($config['version_reports'], FILTER_VALIDATE_BOOLEAN)
                : null,
            reviewLanguages: array_values(array_unique($review)),
            reviewUrl: $reviewUrl,
        );
    }

    /**
     * The setting language_codes as it is saved (onAdminSave): a map { Grav code: Supertext code }
     * with only codes Supertext offers, in their canonical form ("de_ch" → "de-CH"). A language
     * with *Translation review* on is stored as { code: …, review: true } (code '' = the default).
     * Blank and unknown codes are dropped; the older list of {grav, supertext} rows becomes a map.
     *
     * @return array<string, string|array{code: string, review: true}>
     */
    public static function sanitizeLanguageCodes(mixed $value): array
    {
        $codes = [];
        foreach (self::mappingEntries($value) as $grav => $entry) {
            $target = $entry['code'] !== '' ? Languages::matchTarget($entry['code']) : null;
            if ($entry['review']) {
                $codes[$grav] = ['code' => $target ?? '', 'review' => true];
            } elseif ($target !== null) {
                $codes[$grav] = $target;
            }
        }

        return $codes;
    }

    /**
     * The rows of language_codes with a code or review on, from a map (de: de-CH, or
     * fr: { code: fr-CH, review: true }) or the older list of {grav, supertext} rows.
     *
     * @return array<string, array{code: string, review: bool}>
     */
    private static function mappingEntries(mixed $value): array
    {
        $rows = [];
        foreach (is_array($value) ? $value : [] as $key => $entry) {
            $review = false;
            if (is_array($entry) && array_key_exists('grav', $entry)) {
                $key = (string)$entry['grav'];
                $code = $entry['supertext'] ?? '';
            } elseif (is_array($entry)) {
                $code = $entry['code'] ?? '';
                $review = filter_var($entry['review'] ?? false, FILTER_VALIDATE_BOOLEAN);
            } else {
                $code = $entry;
            }
            $key = trim((string)$key);
            $code = is_scalar($code) ? trim((string)$code) : '';
            if ($key !== '' && ($code !== '' || $review)) {
                $rows[$key] = ['code' => $code, 'review' => $review];
            }
        }

        return $rows;
    }

    /**
     * Whether translations into this Grav language go through Supertext's review service
     * (its *Translation review* is on and review_url isn't empty).
     */
    public function reviewFor(string $gravCode): bool
    {
        return $this->reviewUrl !== '' && in_array(strtolower(trim($gravCode)), $this->reviewLanguages, true);
    }

    /**
     * The target code sent to Supertext for a Grav language: the mapping (language_codes),
     * else the Grav code if it is exactly a Supertext code ("de-ch" → "de-CH", "es" → "es"),
     * else the SDK's default ("de" → "de-DE", "zh-tw" → "zh-Hant", "es-es" → "es").
     * Nothing else is guessed: "en-ie", "de-li" or "gsw" need a mapping.
     *
     * @throws SdkException unsupported_language (nothing is sent)
     */
    public function targetCode(string $gravCode): string
    {
        return Languages::target($gravCode, $this->languageCodes);
    }

    /** targetCode(), or null for a language without mapping and default (for the settings and the panel). */
    public function targetCodeOrNull(string $gravCode): ?string
    {
        try {
            return $this->targetCode($gravCode);
        } catch (SdkException) {
            return null;
        }
    }

    /**
     * The source code sent to Supertext for a Grav language, from the same mapping: a mapped
     * "de: de-CH" is sent as "de", "en-us" as "en".
     *
     * @throws SdkException unsupported_language (nothing is sent)
     */
    public function sourceCode(string $gravCode): string
    {
        return Languages::source($gravCode, $this->languageCodes);
    }

    /** sourceCode(), or null while the language has no Supertext source language. */
    public function sourceCodeOrNull(string $gravCode): ?string
    {
        try {
            return $this->sourceCode($gravCode);
        } catch (SdkException) {
            return null;
        }
    }

    /**
     * The form of address sent for a target: the setting, except for targets Supertext offers no
     * formal/informal choice for (en-US, rm, …), which get Supertext's default. The settings
     * screen says so next to those languages; a code the SDK doesn't know gets the setting.
     */
    public function politenessFor(string $targetCode): string
    {
        if ($this->politeness === Politeness::DEFAULT || Languages::supportsPoliteness($targetCode) === false) {
            return Politeness::DEFAULT;
        }

        return $this->politeness;
    }

    /**
     * The key as entered, without the "Supertext-Auth-Key " prefix Supertext shows it with
     * (the SDK strips it too; here it decides whether a key is configured at all).
     */
    public static function normalizeKey(#[\SensitiveParameter] string $key): string
    {
        return trim((string)preg_replace('/^\s*Supertext-Auth-Key\s+/i', '', trim($key)));
    }
}
