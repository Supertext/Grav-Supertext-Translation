<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Reporting;

use Supertext\GravTranslation\Sdk\V1\ReporterState;

/**
 * Where the plugin keeps the Supertext reporter's state between requests: one small JSON file,
 * user/data/supertext-translation/reporter.json (Grav's place for plugin data, next to the
 * site's content, never in the config). The JSON is the SDK's ReporterState as it is: what was
 * last reported, the latest API key check and the key's fingerprint, never the key itself.
 *
 * Reading never fails (a missing or broken file is the empty state); writing failures are
 * swallowed, because the reporter must never break a save or a translation.
 */
final class StateStore
{
    public function __construct(private readonly string $file)
    {
    }

    public function file(): string
    {
        return $this->file;
    }

    public function load(): ReporterState
    {
        $json = is_file($this->file) ? @file_get_contents($this->file) : false;

        return ReporterState::from(is_string($json) ? $json : null);
    }

    /** Whether the state was written. */
    public function save(ReporterState $state): bool
    {
        try {
            $dir = dirname($this->file);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return false;
            }
            $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            // Write next to the file and rename, so a reader never sees half a file.
            $tmp = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
                return false;
            }
            if (!@rename($tmp, $this->file)) {
                @unlink($tmp);
                return false;
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
