<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation\Tests;

use Grav\Plugin\SupertextTranslation\Reporting\Reporting;
use Grav\Plugin\SupertextTranslation\Reporting\StateStore;
use Grav\Plugin\SupertextTranslation\Settings;
use PHPUnit\Framework\TestCase;
use Supertext\GravTranslation\PluginHealth\InstallationReporter;
use Supertext\GravTranslation\Sdk\Http\HttpRequest;
use Supertext\GravTranslation\Sdk\Supertext;

/**
 * The plugin's glue around Supertext Plugin Health's InstallationReporter (with the SDK's key
 * check): state in a file, the actions the plugin runs, the ping answer and the setting. The
 * protocol itself is tested in Plugin Health (plugin-health/spec/reporter.json). Nothing here talks to Supertext or the Commando Center: a fake
 * transport answers the key check (GET features) and the report.
 */
final class ReportingTest extends TestCase
{
    private string $dir;
    private FakeCenter $http;
    private float $time = 1_791_000_000.0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/supertext-reporting-' . bin2hex(random_bytes(4));
        $this->http = new FakeCenter();
        Reporting::flushDeferred();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
        putenv('SUPERTEXT_VERSION_REPORTS');
    }

    private function reporting(bool $enabled = true, string $version = '0.2.0', string $grav = '2.2.4'): Reporting
    {
        return new Reporting(
            store: new StateStore($this->dir . '/reporter.json'),
            enabled: $enabled,
            siteUrl: 'https://www.example.ch',
            pluginVersion: $version,
            gravVersion: $grav,
            callbackUrl: 'https://www.example.ch/api/v1/supertext/ping',
            apiBaseUrl: 'https://api.supertext.com/v1/',
            transport: $this->http,
            reportUrl: 'https://center.test/api/v1/report',
            now: fn (): float => $this->time,
            sleep: static function (float $seconds): void {},
        );
    }

    public function testSettingsSavedChecksTheKeyAndReportsInstalledThenSettingsSaved(): void
    {
        $result = $this->reporting()->run(Reporting::AFTER_SETTINGS_SAVED, 'Supertext-Auth-Key secret-key');

        self::assertNotNull($result);
        self::assertSame('installed', $result->event);
        self::assertTrue($result->sent);
        self::assertSame('ok', $result->check?->status);
        self::assertSame(['GET https://api.supertext.com/v1/features', 'POST https://center.test/api/v1/report'], $this->http->calls());

        $report = $this->http->reports[0];
        self::assertSame('Grav-Supertext-Translation', $report['plugin']['name']);
        self::assertSame('0.2.0', $report['plugin']['version']);
        self::assertSame(['name' => 'Grav', 'version' => '2.2.4'], $report['system']);
        self::assertSame('PHP', $report['runtime']['name']);
        self::assertSame(Supertext::SDK_VERSION, $report['sdk_version'], 'the bundled SDK');
        self::assertSame('https://www.example.ch', $report['site_url']);
        self::assertSame('https://www.example.ch/api/v1/supertext/ping', $report['callback']['url']);
        self::assertTrue($report['api_key_configured']);
        self::assertSame('ok', $report['api_key_check']['status']);

        $again = $this->reporting()->run(Reporting::AFTER_SETTINGS_SAVED, 'secret-key');
        self::assertSame('settings_saved', $again?->event);
    }

    public function testTheKeyIsNeverStoredOrSent(): void
    {
        $this->reporting()->run(Reporting::AFTER_SETTINGS_SAVED, 'secret-key');
        $stored = (string)file_get_contents($this->dir . '/reporter.json');

        self::assertStringNotContainsString('secret-key', $stored);
        self::assertStringContainsString(hash('sha256', 'secret-key'), $stored, 'only the fingerprint, to notice a changed key');
        self::assertStringNotContainsString('secret-key', json_encode($this->http->reports, JSON_THROW_ON_ERROR));
        // Only the key check (to Supertext) carries the key, in its auth header.
        self::assertSame('Supertext-Auth-Key secret-key', $this->http->requests[0]->headers['Authorization']);
        self::assertArrayNotHasKey('Authorization', $this->http->requests[1]->headers);
    }

    public function testBackEndRequestsReportOnlyWhatChanged(): void
    {
        $this->reporting()->run(Reporting::AFTER_SETTINGS_SAVED, 'secret-key');
        $calls = count($this->http->requests);

        $nothing = $this->reporting()->run(Reporting::ON_REQUEST, 'secret-key');
        self::assertNull($nothing?->event);
        self::assertCount($calls, $this->http->requests, 'same versions, same key: no request at all');

        $upgraded = $this->reporting(version: '0.3.0')->run(Reporting::ON_REQUEST, 'secret-key');
        self::assertSame('upgraded', $upgraded?->event);
        self::assertSame('0.3.0', end($this->http->reports)['plugin']['version']);

        $grav = $this->reporting(version: '0.3.0', grav: '2.3.0')->run(Reporting::ON_REQUEST, 'secret-key');
        self::assertSame('upgraded', $grav?->event, 'a Grav update counts too');
    }

    public function testAChangedKeyIsCheckedOnTheNextRequest(): void
    {
        $this->reporting()->run(Reporting::AFTER_SETTINGS_SAVED, 'secret-key');
        $this->http->keyStatus = 401;

        $result = $this->reporting()->run(Reporting::ON_REQUEST, 'another-key');
        self::assertSame('invalid', $result?->check?->status);
        self::assertSame('api_key_check', $result?->event);
    }

    public function testRefusedTranslationMarksTheKeyInvalidWithoutAskingSupertext(): void
    {
        $this->reporting()->run(Reporting::AFTER_SETTINGS_SAVED, 'secret-key');
        $this->http->requests = [];

        $result = $this->reporting()->run(Reporting::AUTHENTICATION_FAILED, 'secret-key', 403);
        self::assertSame('invalid', $result?->check?->status);
        self::assertSame(403, $result?->check?->httpStatus);
        self::assertSame('api_key_check', $result?->event);
        self::assertSame(['POST https://center.test/api/v1/report'], $this->http->calls());

        $second = $this->reporting()->run(Reporting::AUTHENTICATION_FAILED, 'secret-key', 403);
        self::assertNull($second?->event, 'no report for every failed translation');
    }

    public function testCheckAgainButton(): void
    {
        $this->http->keyStatus = 503;
        $result = $this->reporting()->run(Reporting::TEST_CONNECTION, 'secret-key');
        self::assertSame('unreachable', $result?->check?->status);
        self::assertSame('unreachable', $this->reporting()->lastCheck()?->status, 'stored for the settings page');
    }

    public function testOffMeansNoReportButTheKeyIsStillChecked(): void
    {
        $result = $this->reporting(enabled: false)->run(Reporting::AFTER_SETTINGS_SAVED, 'secret-key');

        self::assertSame('ok', $result?->check?->status);
        self::assertFalse($result?->sent);
        self::assertSame([], $this->http->reports);
        self::assertSame(['GET https://api.supertext.com/v1/features'], $this->http->calls());
    }

    public function testMissingKeyIsReportedWithoutAskingSupertext(): void
    {
        $result = $this->reporting()->run(Reporting::AFTER_SETTINGS_SAVED, '');
        self::assertSame('missing', $result?->check?->status);
        self::assertFalse($this->http->reports[0]['api_key_configured']);
        self::assertSame(['POST https://center.test/api/v1/report'], $this->http->calls());
    }

    public function testAnUnreachableCenterNeverFailsAndWaitsAnHour(): void
    {
        $this->http->reportStatus = 503;
        $first = $this->reporting()->run(Reporting::AFTER_SETTINGS_SAVED, 'secret-key');
        self::assertFalse($first?->sent);

        $this->http->reportStatus = 202;
        $this->time += 60;
        self::assertNull($this->reporting()->run(Reporting::ON_REQUEST, 'secret-key')?->event, 'no new attempt within the hour');
        $this->time += 3600;
        self::assertSame('installed', $this->reporting()->run(Reporting::ON_REQUEST, 'secret-key')?->event);
    }

    public function testBackEndRequestsRunAfterTheResponse(): void
    {
        $this->reporting()->runLater(Reporting::ON_REQUEST, 'secret-key');
        self::assertSame(1, Reporting::deferredCount());
        self::assertSame([], $this->http->requests, 'nothing during the request');

        Reporting::flushDeferred();
        self::assertSame(0, Reporting::deferredCount());
        self::assertSame('installed', $this->http->reports[0]['event']);
    }

    public function testStateStoreSurvivesBrokenAndUnwritableFiles(): void
    {
        mkdir($this->dir);
        file_put_contents($this->dir . '/reporter.json', '{not json');
        self::assertNull((new StateStore($this->dir . '/reporter.json'))->load()->reportedVersions);

        $blocked = new StateStore($this->dir . '/reporter.json/inside/reporter.json');
        self::assertFalse($blocked->save((new StateStore($this->dir . '/x.json'))->load()));

        // The reporter still works without being able to store anything.
        $reporting = new Reporting($blocked, true, 'https://www.example.ch', '0.2.0', '2.2.4', null, transport: $this->http, reportUrl: 'https://center.test/api/v1/report');
        self::assertSame('installed', $reporting->run(Reporting::AFTER_SETTINGS_SAVED, 'secret-key')?->event);
    }

    public function testPingAnswer(): void
    {
        $ok = Reporting::ping('abcdefghijklmnop1234', true);
        self::assertSame(200, $ok->status);
        self::assertSame(['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'], $ok->headers);
        self::assertSame('{"ok":true,"plugin":"Grav-Supertext-Translation","challenge":"abcdefghijklmnop1234"}', $ok->body);

        self::assertSame(400, Reporting::ping('<script>', true)->status);
        self::assertSame(400, Reporting::ping(null, true)->status);
        self::assertSame(404, Reporting::ping('abcdefghijklmnop1234', false)->status, 'reports off: no ping');
    }

    public function testSettingAndEnvironment(): void
    {
        self::assertTrue(InstallationReporter::reportsEnabled(Settings::fromArray([])->versionReports), 'on by default');
        self::assertFalse(InstallationReporter::reportsEnabled(Settings::fromArray(['version_reports' => false])->versionReports));

        putenv('SUPERTEXT_VERSION_REPORTS=0');
        self::assertFalse(InstallationReporter::reportsEnabled(Settings::fromArray(['version_reports' => true])->versionReports), 'the environment wins');
        putenv('SUPERTEXT_VERSION_REPORTS=1');
        self::assertTrue(InstallationReporter::reportsEnabled(Settings::fromArray(['version_reports' => false])->versionReports));
    }

    public function testAStateWrittenByTheSdk02ReporterIsKept(): void
    {
        $reporting = $this->reporting();
        mkdir($this->dir);
        $old = json_encode([
            'reportedVersions' => $reporting->reporter()->versions(),
            'reportedKeyStatus' => 'ok',
            'lastFailureAt' => null,
            'keyCheck' => ['status' => 'ok', 'checkedAt' => '2026-10-01T08:00:00Z', 'httpStatus' => 200, 'errorCode' => null, 'fingerprint' => hash('sha256', 'secret-key')],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($this->dir . '/reporter.json', $old);

        self::assertNull($reporting->run(Reporting::ON_REQUEST, 'secret-key')?->event, 'no new installed report after the update');
        self::assertSame([], $this->http->requests);
        self::assertSame($old, file_get_contents($this->dir . '/reporter.json'), 'same file format');
    }
}
