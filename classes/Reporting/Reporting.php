<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Reporting;

use Supertext\GravTranslation\PluginHealth\CallableKeyChecker;
use Supertext\GravTranslation\PluginHealth\InstallationReporter;
use Supertext\GravTranslation\PluginHealth\KeyCheck;
use Supertext\GravTranslation\PluginHealth\PingResponse;
use Supertext\GravTranslation\PluginHealth\ReporterResult;
use Supertext\GravTranslation\Sdk\Http\HttpTransport;
use Supertext\GravTranslation\Sdk\Supertext;
use Supertext\GravTranslation\Sdk\V1\Client;
use Supertext\GravTranslation\Sdk\V1\Protocol;

/**
 * The plugin's side of the Supertext Commando Center: version reports, the automatic API key
 * check and the callback ping (Commando-Center-Supertext-Translation → docs/DEVELOPER.md →
 * Report protocol). The protocol itself is Supertext Plugin Health's InstallationReporter
 * (lib/supertext-plugin-health), with the Supertext SDK's checkApiKey() as key checker; this class
 * only gives it what Grav knows (site URL, versions, the ping URL) and keeps its state in a StateStore.
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
     * @param HttpTransport|null $transport the SDK's transport for the key check and (adapted) the reports; null: cURL
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
            keyChecker: $this->keyChecker(),
            systemVersion: $this->gravVersion,
            sdkVersion: Supertext::SDK_VERSION,
            callbackUrl: $this->callbackUrl,
            reportUrl: $this->reportUrl,
            transport: $this->transport !== null ? new ReportTransport($this->transport) : null,
            now: is_callable($this->now) ? $this->now : null,
        );
    }

    /** The SDK's key check (GET features) against the configured API. */
    private function keyChecker(): CallableKeyChecker
    {
        $baseUrl = $this->apiBaseUrl;
        $userAgent = self::PLUGIN_NAME . '/' . $this->pluginVersion;
        $transport = $this->transport;
        $sleep = is_callable($this->sleep) ? $this->sleep : null;
        $now = is_callable($this->now) ? $this->now : null;

        return new CallableKeyChecker(
            static fn (string $key) => (new Client($key, $baseUrl, userAgent: $userAgent, transport: $transport, sleep: $sleep, now: $now))->checkApiKey(),
            [Protocol::class, 'apiKeyFingerprint'],
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
    public function lastCheck(): ?KeyCheck
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
