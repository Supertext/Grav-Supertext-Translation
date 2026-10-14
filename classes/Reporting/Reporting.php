<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Reporting;

use Supertext\GravTranslation\Sdk\Http\HttpTransport;
use Supertext\GravTranslation\Sdk\V1\ApiKeyCheck;
use Supertext\GravTranslation\Sdk\V1\InstallationReporter;
use Supertext\GravTranslation\Sdk\V1\PingResponse;
use Supertext\GravTranslation\Sdk\V1\Protocol;
use Supertext\GravTranslation\Sdk\V1\ReporterResult;

/**
 * The plugin's side of the Supertext Commando Center: version reports, the automatic API key
 * check and the callback ping (Commando-Center-Supertext-Translation → docs/DEVELOPER.md →
 * Report protocol). The protocol itself is the SDK's InstallationReporter; this class only gives
 * it what Grav knows (site URL, versions, the ping URL) and keeps its state in a StateStore.
 *
 * It has no Grav dependency (GravReporting builds it from Grav), so it is unit-tested on its
 * own. Nothing here ever throws: reporting must never break a save or a translation.
 */
final class Reporting
{
    /** The GitHub repository name, as the Commando Center expects it. */
    public const PLUGIN_NAME = 'Grav-Supertext-Translation';

    /** Path of the public ping route below the API plugin's base (/api/v1). */
    public const PING_PATH = '/supertext/ping';

    public const AFTER_SETTINGS_SAVED = 'afterSettingsSaved';
    public const ON_REQUEST = 'onRequest';
    public const AUTHENTICATION_FAILED = 'authenticationFailed';
    public const TEST_CONNECTION = 'testConnection';

    /** @var list<callable(): void> work queued for after the response (Grav's onShutdown) */
    private static array $deferred = [];

    /**
     * @param bool $enabled InstallationReporter::reportsEnabled() of the setting
     * @param (callable(): float)|null $now wall-clock time, for tests
     * @param (callable(float): void)|null $sleep waits between 429 retries of the key check, for tests
     */
    public function __construct(
        private readonly StateStore $store,
        public readonly bool $enabled,
        private readonly string $siteUrl,
        private readonly string $pluginVersion,
        private readonly ?string $gravVersion,
        private readonly ?string $callbackUrl,
        private readonly string $apiBaseUrl = Protocol::LIVE_BASE_URL,
        private readonly ?HttpTransport $transport = null,
        private readonly ?string $reportUrl = null,
        private readonly mixed $now = null,
        private readonly mixed $sleep = null,
    ) {
    }

    public function reporter(): InstallationReporter
    {
        return new InstallationReporter(
            enabled: $this->enabled,
            siteUrl: $this->siteUrl,
            pluginName: self::PLUGIN_NAME,
            pluginVersion: $this->pluginVersion,
            systemName: 'Grav',
            systemVersion: $this->gravVersion,
            callbackUrl: $this->callbackUrl,
            reportUrl: $this->reportUrl,
            transport: $this->transport,
            apiBaseUrl: $this->apiBaseUrl,
            sleep: is_callable($this->sleep) ? $this->sleep : null,
            now: is_callable($this->now) ? $this->now : null,
        );
    }

    /**
     * Runs one reporter action with the stored state and stores the new state.
     *
     * @return ReporterResult|null null if something unexpected went wrong (never thrown)
     */
    public function run(string $action, #[\SensitiveParameter] string $apiKey, int $httpStatus = 401): ?ReporterResult
    {
        try {
            $reporter = $this->reporter();
            $state = $this->store->load();
            $result = match ($action) {
                self::AFTER_SETTINGS_SAVED => $reporter->afterSettingsSaved($state, $apiKey),
                self::AUTHENTICATION_FAILED => $reporter->authenticationFailed($state, $apiKey, $httpStatus),
                self::TEST_CONNECTION => $reporter->testConnection($state, $apiKey),
                default => $reporter->onRequest($state, $apiKey),
            };
            $this->store->save($result->state);

            return $result;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Runs the action after the response has been sent (see flushDeferred()). */
    public function runLater(string $action, #[\SensitiveParameter] string $apiKey, int $httpStatus = 401): void
    {
        self::$deferred[] = fn () => $this->run($action, $apiKey, $httpStatus);
    }

    /** Runs the queued actions; the plugin calls it on Grav's onShutdown, after the response. */
    public static function flushDeferred(): void
    {
        $tasks = self::$deferred;
        self::$deferred = [];
        foreach ($tasks as $task) {
            try {
                $task();
            } catch (\Throwable) {
                // Never fails anything: the center is optional.
            }
        }
    }

    /** Number of queued actions (for tests). */
    public static function deferredCount(): int
    {
        return count(self::$deferred);
    }

    /** The latest stored API key check, for the settings page. */
    public function lastCheck(): ?ApiKeyCheck
    {
        try {
            return $this->store->load()->keyCheck;
        } catch (\Throwable) {
            return null;
        }
    }

    /** The answer of the public ping route. */
    public static function ping(?string $challenge, bool $enabled): PingResponse
    {
        return InstallationReporter::pingResponse($challenge, self::PLUGIN_NAME, $enabled);
    }
}
