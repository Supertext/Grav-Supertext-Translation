<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation;

use Grav\Common\Grav;
use Grav\Plugin\SupertextTranslation\Api\SupertextException;

/**
 * Plugin messages in the admin user's language (languages.yaml, PLUGIN_SUPERTEXT_TRANSLATION.*).
 *
 * The API plugin's own controllers translate against the user's admin language, not the site's
 * content language; this does the same for the plugin's panel label and API messages.
 */
final class Messages
{
    private const PREFIX = 'PLUGIN_SUPERTEXT_TRANSLATION.';

    /** @param list<string> $languages most specific first, e.g. [fr, en] */
    private function __construct(private readonly Grav $grav, private readonly array $languages)
    {
    }

    /** For the given admin user (null: English). */
    public static function forUser(Grav $grav, mixed $user): self
    {
        $language = 'en';
        $resolver = 'Grav\\Plugin\\Api\\Services\\PreferencesResolver';
        if ($user !== null && class_exists($resolver)) {
            try {
                $effective = (new $resolver($grav))->resolve($user, false)['effective'] ?? [];
                if (is_string($effective['adminLanguage'] ?? null) && $effective['adminLanguage'] !== '') {
                    $language = $effective['adminLanguage'];
                }
            } catch (\Throwable) {
                // No preferences: English.
            }
        }
        $primary = strtolower((string)strtok($language, '-_'));

        return new self($grav, array_values(array_unique([$language, $primary, 'en'])));
    }

    /** PLUGIN_SUPERTEXT_TRANSLATION.<key>, with sprintf-style $args; $fallback (English) if it isn't defined. */
    public function text(string $key, array $args = [], string $fallback = ''): string
    {
        $full = self::PREFIX . $key;
        try {
            $text = $this->grav['language']->translate(array_merge([$full], $args), $this->languages);
        } catch (\Throwable) {
            $text = $full;
        }
        if (!is_string($text) || $text === '' || $text === $full) {
            return $fallback !== '' ? $fallback : $full;
        }

        return $text;
    }

    /** An exception's message in the user's language. */
    public function error(\Throwable $e): string
    {
        if ($e instanceof SupertextException && $e->reason !== '') {
            return $this->reason($e->reason, $e->args, $e->detail, $e->getMessage());
        }

        return $e->getMessage();
    }

    /** @param list<string|int> $args */
    public function reason(string $reason, array $args, string $detail, string $english): string
    {
        $text = $this->text('ERRORS.' . strtoupper($reason), $args, $english);
        if ($text === $english) {
            return $english;
        }

        return $detail !== '' ? $text . ' (' . $detail . ')' : $text;
    }
}
