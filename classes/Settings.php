<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation;

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
    ) {}

    /**
     * The settings with SUPERTEXT_API_KEY and SUPERTEXT_API_URL from the environment.
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        $key = getenv('SUPERTEXT_API_KEY');
        $url = getenv('SUPERTEXT_API_URL');

        return self::fromArray($config, $key === false ? null : $key, $url === false ? null : $url);
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config, ?string $environmentKey = null, ?string $environmentUrl = null): self
    {
        $apiKey = self::normalizeKey((string)($config['api_key'] ?? ''));
        if ($apiKey === '' && $environmentKey !== null) {
            $apiKey = self::normalizeKey($environmentKey);
        }

        $codes = [];
        foreach (self::mappingRows($config['language_codes'] ?? []) as $key => $value) {
            $codes[strtolower($key)] = $value;
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
        );
    }

    /**
     * The setting language_codes as it is saved (onAdminSave): a map { Grav code: Supertext code }
     * with only codes Supertext offers, in their canonical form ("de_ch" → "de-CH"). Blank and
     * unknown codes are dropped; the older list of {grav, supertext} rows becomes a map.
     *
     * @return array<string, string>
     */
    public static function sanitizeLanguageCodes(mixed $value): array
    {
        $codes = [];
        foreach (self::mappingRows($value) as $grav => $supertext) {
            $target = Languages::matchTarget($supertext);
            if ($target !== null) {
                $codes[$grav] = $target;
            }
        }

        return $codes;
    }

    /**
     * The non-blank rows of language_codes, from a map (de: de-CH) or a list of {grav, supertext} rows.
     *
     * @return array<string, string>
     */
    private static function mappingRows(mixed $value): array
    {
        $rows = [];
        foreach (is_array($value) ? $value : [] as $key => $code) {
            if (is_array($code)) {
                $key = (string)($code['grav'] ?? '');
                $code = (string)($code['supertext'] ?? '');
            }
            $key = trim((string)$key);
            $code = is_scalar($code) ? trim((string)$code) : '';
            if ($key !== '' && $code !== '') {
                $rows[$key] = $code;
            }
        }

        return $rows;
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
