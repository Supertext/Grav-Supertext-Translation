<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation;

use Grav\Plugin\SupertextTranslation\Api\LanguageCodes;
use Grav\Plugin\SupertextTranslation\Api\SupertextClient;

/** Plugin settings (user/config/plugins/supertext-translation.yaml), with defaults. */
final class Settings
{
    /**
     * @param array<string, string> $languageCodes Grav language code => Supertext language code
     * @param list<string> $headerFields front matter fields to translate (dot paths)
     */
    public function __construct(
        public readonly string $apiKey = '',
        public readonly string $apiUrl = SupertextClient::DEFAULT_BASE_URL,
        public readonly string $sourceLanguage = '',
        public readonly array $languageCodes = [],
        public readonly string $politeness = 'default',
        public readonly array $headerFields = ['title', 'menu', 'metadata.description'],
        public readonly bool $publishNewTranslations = false,
        public readonly int $pollInterval = 2,
        public readonly int $pollTimeout = 300,
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config, ?string $environmentKey = null, ?string $environmentUrl = null): self
    {
        $apiKey = SupertextClient::normalizeKey((string)($config['api_key'] ?? ''));
        if ($apiKey === '' && $environmentKey !== null) {
            $apiKey = SupertextClient::normalizeKey($environmentKey);
        }

        $codes = [];
        foreach ((array)($config['language_codes'] ?? []) as $key => $value) {
            // Accept both a map (de: de-CH) and a list of {grav, supertext} rows.
            if (is_array($value)) {
                $key = (string)($value['grav'] ?? '');
                $value = (string)($value['supertext'] ?? '');
            }
            $key = strtolower(trim((string)$key));
            $value = trim((string)$value);
            if ($key !== '' && $value !== '') {
                $codes[$key] = $value;
            }
        }

        $fields = array_values(array_filter(array_map(
            static fn($f): string => trim((string)$f),
            (array)($config['header_fields'] ?? ['title', 'menu', 'metadata.description']),
        ), static fn(string $f): bool => $f !== ''));

        $politeness = (string)($config['politeness'] ?? 'default');

        return new self(
            apiKey: $apiKey,
            // SUPERTEXT_API_URL (testing only) wins over the setting, e.g. for a local stand-in API.
            apiUrl: trim((string)$environmentUrl) ?: trim((string)($config['api_url'] ?? '')) ?: SupertextClient::DEFAULT_BASE_URL,
            sourceLanguage: strtolower(trim((string)($config['source_language'] ?? ''))),
            languageCodes: $codes,
            politeness: in_array($politeness, ['default', 'more', 'less'], true) ? $politeness : 'default',
            headerFields: $fields,
            publishNewTranslations: (bool)($config['publish_new_translations'] ?? false),
            pollInterval: max(1, (int)($config['poll_interval'] ?? 2)),
            pollTimeout: max(10, (int)($config['poll_timeout'] ?? 300)),
        );
    }

    /**
     * The target code sent to Supertext for a Grav language, always with a region: the
     * mapping, else the Grav code ("de-ch" → "de-CH", bare "de" → "de-DE").
     */
    public function supertextCode(string $gravCode): string
    {
        return LanguageCodes::target($gravCode, $this->languageCodes[strtolower($gravCode)] ?? '');
    }

    /** The source code sent to Supertext for a Grav language: the bare language ("de-ch" → "de"). */
    public function sourceCode(string $gravCode): string
    {
        return LanguageCodes::source($gravCode, $this->languageCodes[strtolower($gravCode)] ?? '');
    }
}
