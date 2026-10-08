<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Api;

/**
 * An error whose message is written for the editor and safe to show in the admin.
 *
 * The message is English. $reason (e.g. "limit_exceeded") lets the plugin show its own
 * translation (PLUGIN_SUPERTEXT_TRANSLATION.ERRORS.<REASON>, with $args) in the user's
 * admin language; $detail, if set, is appended in brackets.
 */
final class SupertextException extends \RuntimeException
{
    /** @param list<string|int> $args */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly string $reason = '',
        public readonly array $args = [],
        public readonly string $detail = '',
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @param string $message English text, with %s / %d placeholders for $args
     * @param list<string|int> $args
     */
    public static function because(string $reason, string $message, array $args = [], string $detail = ''): self
    {
        $message = $args ? vsprintf($message, $args) : $message;

        return new self($detail !== '' ? $message . ' (' . $detail . ')' : $message, 0, null, $reason, $args, $detail);
    }
}
