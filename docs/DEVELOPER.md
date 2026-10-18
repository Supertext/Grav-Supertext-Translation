# Developer guide

Architecture, the Supertext protocol, local setup, tests, the demo and releasing.

## Architecture

Grav 2 replaced its classic admin with **Admin2**, a single-page app that talks to Grav only through the **API plugin**. Admin2 does not fire the old `onAdmin*` page-editor events, so the plugin hooks into the extension points of the API plugin:

| Piece | File | What it does |
| --- | --- | --- |
| Plugin class | `supertext-translation.php` | Registers the class autoloader (the plugin's classes, the bundled SDK and Supertext Plugin Health), the API routes (`onApiRegisterRoutes`), the page-editor panel (`onApiContextPanels`), the public ping route (`onApiCollectPublicRoutes`), the key check and report after a settings save (`onApiConfigUpdated`), keeps the status field out of the config file and checks the `language_codes` against the Supertext languages (`onAdminSave`) and runs queued reporter work after the response (`onShutdown`). |
| Panel | `admin-next/panels/supertext-translation.js` | A custom element that Admin2 loads from `GET /api/v1/gpm/plugins/supertext-translation/panel-script` when the toolbar button is clicked. Admin2 sets its `route`, `lang` and `type` attributes; it fires `close` and `badge`. It calls the API with the user's token (`X-API-Token`). |
| Settings fields | `admin-next/fields/supertext-status.js`, `supertext-languages.js` | Custom Admin2 field types used in `blueprints.yaml`, loaded from `GET /api/v1/gpm/plugins/supertext-translation/fields`. `supertext-status` (field `supertext_status`, no value) shows the latest API key check and *Check again*; `supertext-languages` edits `language_codes` with one select of the Supertext languages per site language, preselected with the exact code or the SDK default (see *Language codes*), and a *Translation review* checkbox per target language (see *Translation review*). |
| Settings controller | `classes/Controller/SettingsController.php` | `GET /api/v1/supertext/settings` (site languages with their Supertext codes, the targets list, the latest key check, the reporting state) and `POST /api/v1/supertext/check` (*Check again*). Needs `api.config.read`. |
| Ping controller | `classes/Controller/PingController.php` | `GET /api/v1/supertext/ping?challenge=…`, public, for the Supertext Commando Center (see *Version reports and API key check*). |
| Reporting | `classes/Reporting/Reporting.php`, `StateStore.php`, `GravReporting.php`, `ReportTransport.php` | The plugin's side of the Commando Center around Supertext Plugin Health's `InstallationReporter` (with the SDK's key check): state file, the actions, deferred work, the ping answer. `GravReporting` fills in the site URL, versions and paths from Grav; the other two have no Grav dependency (unit-tested). |
| API controller | `classes/Controller/TranslationController.php` | `GET /api/v1/supertext/status?route=…`, `POST /api/v1/supertext/translate` (`{route, languages, overwrite}`), `POST /api/v1/supertext/export` and `/import` (translation files, see *Translation files*). Extends the API plugin's `AbstractApiController`, so authentication, page ACL (`authorizePageAction(…, 'update', 'api.pages.write')`), error format (RFC 7807) and cache invalidation headers work like the core routes. |
| Translator | `classes/PageTranslator.php` | Reads the source file, packs the front matter fields (plain text) and the body blocks (HTML) into one document with the SDK's `HtmlDocument`, sends it to Supertext for all target languages, writes `<template>.<lang>.md`, tracks states. Independent of Grav (testable). |
| Translation files | `classes/FileExchange.php` | Export for translation / Import translation: references, jobs, import statuses (the SDK's `Files\TranslationFiles`). Independent of Grav (testable). |
| Markdown | `classes/Markdown/MarkdownDocument.php`, `InlineConverter.php` | Splits the Markdown body into blocks and back; converts inline Markdown to HTML and back. |
| Supertext SDK | `lib/supertext-sdk/` | The bundled [Supertext SDK for PHP](https://github.com/Supertext/Supertext-API-SDK-and-Guide/tree/main/sdk/php) 0.5.0: the client (protocol, retries, HTML packing, the list of Supertext languages, error codes), the API key check and the translation files (`Files\TranslationFiles`, no API calls) (see *Supertext API protocol* and *Bundled Supertext SDK*). |
| Supertext Plugin Health | `lib/supertext-plugin-health/` | The bundled [Supertext Plugin Health](https://github.com/Supertext/Commando-Center-Supertext-Translation/tree/main/plugin-health) 0.1.0 (PHP): version reports to the Commando Center, the key check result and the ping answer (see *Supertext Plugin Health*). |
| Supertext client | `classes/Api/SupertextClient.php`, `SupertextException.php` | The plugin's layer over the SDK: translates into several languages in parallel, sends languages with *Translation review* through the review service (`fromSettings()`, a second SDK client) and turns the SDK's error codes into the plugin's translatable reasons. |
| Version | `classes/PluginVersion.php` | The version from `blueprints.yaml`, sent to Supertext in the User-Agent (`Grav-Supertext-Translation/<version>`) and in the version reports. |
| Settings | `classes/Settings.php`, `blueprints.yaml`, `supertext-translation.yaml` | Settings with defaults, the Admin2 form, defaults file. |
| Strings | `languages.yaml`, `classes/Messages.php` | All UI strings in English, German, French and Italian (see *Interface languages* below). `Messages` resolves the admin user's language (the API plugin's `PreferencesResolver`, as its own controllers do) and translates the panel label and the API's messages. |

### Interface languages

Everything the plugin shows is in `languages.yaml`, in `en`, `de`, `fr` and `it`:

- `PLUGIN_SUPERTEXT_TRANSLATION.SETTINGS.*`: the settings form. `blueprints.yaml` holds only these keys; Admin2 translates them into the user's admin language.
- `PLUGIN_SUPERTEXT_TRANSLATION.PANEL_LABEL` and `PLUGIN_SUPERTEXT_TRANSLATION.ERRORS.*`: the panel's toolbar label and the messages of the API routes (Grav strings, `%s`/`%d` placeholders). The Supertext client and `PageTranslator` stay free of Grav: they throw `SupertextException` (or return results) with an English message and a `reason` (e.g. `limit_exceeded`, args, detail), and `Messages` shows `ERRORS.<REASON>` instead.
- `ICU.PLUGIN_SUPERTEXT_TRANSLATION.PANEL.*`: the panel, read with Admin2's `window.__GRAV_I18N.t()` (ICU MessageFormat, `{name}` placeholders; use `’`, not `'`, in these strings). The panel script keeps the English strings as `EN` for an Admin2 without `__GRAV_I18N`.
- `ICU.PLUGIN_SUPERTEXT_TRANSLATION.FIELDS.*`: the two settings fields, the same way (each field script keeps `EN` for the keys it uses). `FIELDS.KEYCHECK_*` are the Commando Center's API key check messages; the field turns *supertext.com* and *supertext.com → Integrations → API* in them into links (new tab).

New or changed strings need all four languages in the same commit, with formal address (Sie, vous, Lei) and Grav's own terms. `tests/LanguagesTest.php` checks that all languages have the same keys and placeholders, that every key used by `blueprints.yaml`, the panel, the fields and the PHP code exists, and that the scripts' `EN` match the English strings.

### Files and states

Grav stores languages as sibling files: `01.home/default.en.md` → `01.home/default.de.md`. The source is `<template>.<source>.md`, else `<template>.md`. The source file is never written.

A translation gets a block in its front matter:

```yaml
supertext:
  language: de-CH
  source_hash: …        # sha1 of the source's translatable content when translated
  translation_hash: …   # sha1 of the translation's translatable content as written
  translated: '2026-10-05T20:59:43Z'
```

"Translatable content" is the configured front matter fields plus the body, with line endings and trailing spaces normalized. From that:

| State | Condition | Translate again? |
| --- | --- | --- |
| `missing` | no file | yes |
| `current` | `translation_hash` matches, `source_hash` matches | yes (no-op content-wise) |
| `outdated` | `translation_hash` matches, source changed | yes |
| `edited` | `translation_hash` differs | only with `overwrite: true` |
| `manual` | file without `supertext` block | only with `overwrite: true` |

Because only translatable content is hashed, publishing a translation or changing its options does not make it "edited". On update, the source header is copied and the translation's own `published`, `slug`, `routes`, `visible`, `publish_date`, `unpublish_date` are kept. New translations get `published: false` unless `publish_new_translations` is on.

### Rich text and segments

Each block (paragraph, heading, list item, table cell, HTML block line) becomes **one** element with `data-st-id` (the SDK's `HtmlDocument` writes `<div data-st-id="N">`; front matter fields go as plain-text segments). Inline formatting is sent as HTML tags inside it (`<b>`, `<i>`, `<s>`, `<a href title>`), so Supertext translates whole sentences and can move the formatting with the words. The translated HTML is converted back to Markdown (`**`, `*`, `~~`, `[text](url "title")`).

Protected tokens are replaced before sending and restored from the source afterwards, whatever Supertext does with them:

- `<code data-k="N">text</code>` for code spans and autolinks (text visible for context),
- `<img data-k="N" alt="">` for images, Twig and similar.

Kept verbatim (never sent): fenced and indented code, Twig tag lines, shortcode tag lines, link reference definitions, rules, table separators, GitHub alert markers, lines without letters. Text that starts like block syntax after translation (`# `, `- `, `1. `, `>`, `|`) is escaped.

Known simplifications: soft-wrapped paragraph lines are joined into one line; `_emphasis_` is written back as `*emphasis*`; image alt text in Markdown and Twig inside text are not translated; modular sections and child pages are separate pages.

### Translation files

*Export for translation* and *Import translation* in the panel (bundled SDK 0.5.0, `Supertext\GravTranslation\Sdk\Files\TranslationFiles`; SDK integration guide → *Translation files: XLIFF and HTML*). Nothing calls the API.

- **Routes:** `POST /api/v1/supertext/export` (`{route, languages, format: xliff|html}`, same page ACL as translating) answers with the file as attachment, which the panel saves through a blob link; `POST /api/v1/supertext/import` (multipart: `file`, `include_changed`, `overwrite`) answers `{results}`. Uploads over 10 MB (`FileExchange::MAX_UPLOAD_BYTES`; the panel checks the size first too) are refused before reading; the upload is read from PHP's temporary file only.
- **Job:** one page from the source language into one site language. `plugin` = `Grav-Supertext-Translation`; `reference` = `page:<route>:<Grav language>`, e.g. `page:/blog/hello:de` (`FileExchange::reference()` / `parseReference()`; Grav pages have no ids, the route is what the API uses — a page moved after the export is *not found*); `sourceLanguage`/`targetLanguage` = `Settings::sourceCodeOrNull()`/`targetCodeOrNull()`, else the Grav code with `_` → `-` (an unmapped language doesn't block an export).
- **Segments:** exactly those of `translate()`, from the same `collect()`: `PageTranslator::fileSegments()` keys them `field.<front matter field>` (plain text) and `body.<block>` (HTML, the same `<b>`/`<i>`/`<a>`/protected-token markup).
- **Export:** XLIFF: one job per language, `grav-<route>-<lang>.xlf` (without the language for several), `application/x-xliff+xml`; HTML: exactly one language, `.html`, `text/html; charset=utf-8`. Nothing to translate → *Nothing to translate.*
- **Import:** `FileExchange::read()` (errors `invalid_file`, `missing_metadata`, `too_large` → `ERRORS.FILE_<CODE>`), then per job `FileExchange::import()`: `plugin` must be this plugin (`other_system`), the reference must parse and the route resolve (`not_found`), the user must be allowed to update the page (`forbidden`), the language must be another site language (`no_language`). The page's segments are collected again and `importTranslations()` compares them: `ok` is written, `source_changed` only with `include_changed`, `untranslated`/`invalid_markup`/`missing` never.
- **Write-back:** `PageTranslator::writeImported()` runs `translate()`'s write path: the same overwrite protection (*edited*/*manual* kept unless `overwrite`, reason `kept_edited`/`kept_manual`), `buildTranslation()` (front matter of an existing translation kept, new translations unpublished unless configured, the `supertext` stamp), cache clear and invalidation headers. As with segments missing from an API answer, texts without an imported translation are written in the source language (one file per language: there is no per-text "keep").

## Supertext API protocol

AI file translation API v1, same as the other Supertext plugins. The plugin talks to it through the bundled **Supertext SDK for PHP** (MIT, namespace `…\Sdk\V1`): the SDK implements the steps below, the HTML packing, the list of Supertext languages, the 429 retries and stable error codes, and is tested against the shared test vectors of all Supertext SDKs. Protocol fixes go into the SDK, never into this plugin.

| Step | Request |
| --- | --- |
| Check key (free) | `GET https://api.supertext.com/v1/features` |
| Submit | `POST /v1/translate/ai/file` multipart: `file` (Content-Type exactly `text/html`, no charset), `target_lang` (an exact Supertext code, e.g. `de-CH`, `es`), `source_lang` (bare language, `en`; see *Language codes*), optional `politeness` (`more`/`less`) → `{"file_id": …}` |
| Poll | `GET /v1/translate/ai/file/{id}/status` → `in_progress`, `done`, `error`, `limit_exceeded`, `deleted` |
| Download | `GET /v1/translate/ai/file/{id}/translation` → translated HTML |
| Clean up | `DELETE /v1/translate/ai/file/{id}` (always, also after errors) |

Lessons applied from the live API (October 2026):

- Header `Authorization: Supertext-Auth-Key <key>`; exactly one prefix. The SDK strips a pasted prefix (`Settings::normalizeKey()` does the same only to decide whether a key is set).
- Rate limit per key and second (HTTP 429): the SDK retries up to 4 times, honouring `Retry-After`, else 1/2/4/8 s with jitter.
- All target languages are submitted first (`submit()`), then each is awaited, downloaded and deleted in turn (`waitUntilDone()`, `download()`, `delete()`), so Supertext works on them in parallel and three languages take about as long as one. One failing language doesn't stop the others; a missing or refused key stops the rest without sending them. Files are always deleted, also after errors.
- Errors: `Api\SupertextClient::fromSdk()` maps the SDK's error codes (`authentication_failure`, `invalid_language_pair`, `unsupported_language`, `unsupported_feature`, `quota_exceeded`, …) to the plugin's reasons (`auth_failed`, `invalid_language_pair`, `unsupported_language`, `unsupported_feature`, `limit_exceeded`, …), which `languages.yaml` translates (`ERRORS.<REASON>`), and keeps Supertext's HTTP status (a 401/403 marks the key as rejected, see below). A test checks that every SDK code has a reason. A language Supertext doesn't know at all (HTTP 404 with `INVALID_LANGUAGE_PAIR`) is `invalid_language_pair` too, with the two codes, not `not_found`.
- Documents over 900,000 characters are refused before sending.

**Translation review** (per target language, `language_codes.<code>.review`, the checkbox in the `supertext-languages` field): `SupertextClient::fromSettings()` builds the SDK client for `api_url` and, unless `review_url` is empty or the same URL, a factory for a second SDK client with the same key, timeouts, transport and User-Agent whose base URL is `review_url` (default `SupertextClient::REVIEW_URL`, the translation proxy of [Supertext Localization Review](https://github.com/Supertext/Localization-Review-Supertext-Translation); `SUPERTEXT_REVIEW_URL` wins if not empty). The second client is created lazily, the first time a job has `review: true` (`PageTranslator` sets it from `Settings::reviewFor()`), and each job is collected (status, download, delete) with the client that submitted it. The proxy speaks the same API v1, forwards to the live Supertext API with the plugin's key, stores every segment per key and serves reviewers' corrections on the next upload, so nothing else changes in the plugin. `review_url: ''` means everything goes directly (the field disables the checkboxes, `review_available: false` from `GET /api/v1/supertext/settings`). The key check and the version reports build their own client (`Reporting`) and never use the review service. `language_codes` stores a language with review on as `{ code: <Supertext code or ''>, review: true }` (a plain code otherwise; `Settings::sanitizeLanguageCodes()` keeps that form), so existing settings start with review off.

Verified against the live API on 2026-10-05: the demo's About page into `de-CH` and `fr-CH` in 7 to 9 s (locally and on the Railway demo), with bold text moved correctly inside the translated sentence.

### Language codes

Supertext accepts only the codes of `GET features` (the SDK's `Languages::TARGETS` and `Languages::SOURCES`, October 2026): targets are regional only for German, English, French, Italian and Portuguese (`de-CH`, `en-US`, `pt-BR`), have a script for Chinese and Serbian (`zh-Hans`, `sr-Latn`), a dialect for Swiss German (`gsw-u-sd-chzh`), and are bare for all others (`es`, `nl`, `sl`). Sources are bare (`en`). Anything else is rejected (`INVALID_LANGUAGE_PAIR`); the former default regions `es-ES`, `sl-SI`, `zh-CN` were wrong.

Defaults only where obvious: `Settings::targetCode()` and `Settings::sourceCode()` resolve every Grav language with the SDK's `Languages::target()` / `Languages::source()` and **one** mapping for both directions, the `language_codes` setting:

1. The mapping wins (Grav code compared without case and `_`/`-`); its value is sent as it is (a source in its source form: mapped `de-CH` → `de`).
2. Else a Grav code that equals a Supertext code apart from case and `_`/`-` (`de-ch` → `de-CH`, `es` → `es`; sources also by their first subtag, `en-us` → `en`).
3. Else, for targets, the SDK default (`Languages::defaultTarget()`, SDK 0.4.0): `de`/`en`/`fr`/`it`/`pt` → `de-DE`/`en-US`/`fr-FR`/`it-IT`/`pt-PT`, `zh` → `zh-Hans`, `sr` → `sr-Latn`, `zh-tw`/`hk`/`mo` → `zh-Hant`, `zh-cn`/`sg` → `zh-Hans`, a region of a bare target → the bare target (`es-es` → `es`).
4. Else `unsupported_language`: nothing is sent for that language (`en-ie`, `de-li`, `gsw`, `sr-rs`, `no` need a mapping). `PageTranslator` reports it per target language (the others are translated) and stops before sending anything when the source has no code. The panel shows such languages as *not mapped* and can't select them; the settings field shows *Not mapped*.

**The settings field** (`admin-next/fields/supertext-languages.js`, data from `GET /api/v1/supertext/settings`): one row per Grav language (`languages[]` with `suggested` = `Languages::suggestTarget()`, `is_default`, `target`, `source_code`) and a native select of `targets` (`Languages::targetOptions()`), named in the admin language with the browser's `Intl.DisplayNames` (else the SDK's English name) and the code next to it. Preselection: the saved value, else `suggested`, else *— choose —*. The value is a map of the rows the administrator changed; rows on their suggestion aren't stored, so they follow the SDK's defaults. On save, `onAdminSave` passes `language_codes` through `Settings::sanitizeLanguageCodes()`: only codes in `Languages::TARGETS`, in their canonical form; the older list of `{grav, supertext}` rows becomes a map, so nothing configured is lost. A YAML entry with a code the list doesn't know (hand-edited) is shown as *… (not in the list)* until the next save.

Formal/informal (`politeness`) is sent only for targets with `Languages::supportsPoliteness()` true (`Settings::politenessFor()`); the others (English, Romansh, Albanian, Serbian, Swiss German) get Supertext's default, and the languages field says so next to them. A mapped code the SDK doesn't know gets the setting, so a refusal shows as `unsupported_feature`.

`tests/FakeSupertext.php` and the stand-in (`scripts/stand-in-api/router.php`) accept only the SDK's codes, like the real API.

### Bundled Supertext SDK

The plugin ships the SDK in `lib/supertext-sdk/`: its PHP sources, `LICENSE`, `COMMIT` (the full SDK commit SHA), `VERSION` (the SDK's `SDK_VERSION`, sent in the version reports) and a short README, with the namespace changed from `Supertext\Sdk` to `Supertext\GravTranslation\Sdk` (sub-namespaces too: `…\Sdk\V1` for everything that talks to API v1, `…\Sdk\Http` for the transport, `…\Sdk\Files` for translation files). The plugin's own autoloader in `supertext-translation.php` loads it (Grav doesn't install plugins with Composer); `composer.json` maps it too, for the tests and PHPStan. Sites install only the plugin, and another plugin that brings the SDK itself can't clash with this copy. This stays so after the SDK is published on Packagist.

- Update it with `scripts/vendor-sdk.sh [commit-or-branch]` (default `main`; `SDK_DIR=<clone>` uses a local clone), run `composer test`, and commit with a CHANGELOG entry.
- Never edit the copy: fix the SDK in its own repository, then re-run the script. PHPStan reads it (`scanDirectories`) but analyses only the plugin's own code.
- The SDK's built-in HTTP transport uses ext-curl, as the plugin did before.

### Supertext Plugin Health

The version reports, the key check result and the ping answer (see *Version reports and API key check*) come from [Supertext Plugin Health](https://github.com/Supertext/Commando-Center-Supertext-Translation/tree/main/plugin-health), a dependency-free library in the Commando Center repository (`plugin-health/php`). It has been out of the SDK since SDK 0.3.0 and doesn't need it: `Reporting` passes it the SDK's key check (`CallableKeyChecker` with `Client::checkApiKey()` and `Protocol::apiKeyFingerprint()`) and `Supertext::SDK_VERSION`. The plugin ships it in `lib/supertext-plugin-health/` like the SDK: PHP sources, `LICENSE`, `COMMIT`, `VERSION` and a short README, namespace `Supertext\PluginHealth` changed to `Supertext\GravTranslation\PluginHealth` (and `…\PluginHealth\Http`), loaded by the plugin's autoloader and mapped in `composer.json`.

- Update it with `scripts/vendor-plugin-health.sh <tag>` (e.g. `plugin-health-v0.1.0`; default `main`; `PLUGIN_HEALTH_DIR=<clone>` uses a local clone of the Commando Center repository), run `composer test`, and commit with a CHANGELOG entry.
- Never edit the copy: fix it in Commando-Center-Supertext-Translation/plugin-health, then re-run the script. PHPStan reads it (`scanDirectories`) but doesn't analyse it.
- Its built-in transport uses ext-curl too; in the tests `ReportTransport` sends its reports through the SDK transport given to `Reporting`.

## Version reports and API key check

Every Supertext plugin checks its API key automatically and reports its versions to the Supertext Commando Center (protocol: Commando-Center-Supertext-Translation → `docs/DEVELOPER.md` → *Report protocol*). The protocol is Supertext Plugin Health's `InstallationReporter`; `classes/Reporting/` only feeds it:

| Option | Value |
| --- | --- |
| `pluginName` | `Grav-Supertext-Translation` (the GitHub repository name) |
| `pluginVersion` | `PluginVersion::get()` (from `blueprints.yaml`) |
| `systemName`, `systemVersion` | `Grav`, `GRAV_VERSION` (runtime: PHP and `PHP_VERSION`, Plugin Health's default) |
| `sdkVersion` | the SDK's `Supertext::SDK_VERSION` |
| `keyChecker` | the SDK's `checkApiKey()` and `apiKeyFingerprint()` (`CallableKeyChecker`) |
| `siteUrl` | `system.custom_base_url` if absolute, else `Uri::rootUrl(true)` (with a sub-folder if Grav runs in one) |
| `callbackUrl` | site URL + the API plugin's base (`plugins.api.route`/`version_prefix`, default `/api/v1`) + `/supertext/ping` |
| `enabled` | `InstallationReporter::reportsEnabled($settings->versionReports)`: the `version_reports` setting, overridden by `SUPERTEXT_VERSION_REPORTS` |
| key check API | the `api_url` setting (or `SUPERTEXT_API_URL`), the SDK client's base URL |

**When it runs:**

| Moment | Where | Action |
| --- | --- | --- |
| Plugin settings saved in Admin2 | `onApiConfigUpdated` with scope `plugins/supertext-translation` (fired by the API plugin's `ConfigController` after writing) | `afterSettingsSaved`: checks the key, reports `installed` or `settings_saved`. Runs during the save request, so the settings page shows the new check; the status field reloads a few seconds after a button is pressed on the page. |
| Panel opened, translation, settings page opened | `TranslationController::status()`/`translate()`, `SettingsController::show()` | `onRequest`, queued with `Reporting::runLater()` and run in Grav's `onShutdown` after the response was sent (Grav closes the connection first): reports `installed`, `upgraded` (plugin, Grav or PHP version changed) or `api_key_check` (key changed), else nothing. |
| A translation got 401/403 | `TranslationController::translate()` (from the result's `status`) | `authenticationFailed`, after the response. |
| *Check again* on the settings page | `SettingsController::check()` | `testConnection`, during the request. |

The plugin has no CLI command, so `reportCheck` isn't used. Nothing here ever throws or blocks a save or translation: Plugin Health never throws, `Reporting::run()` catches everything else, and a failed report waits an hour (in the state).

**State:** the reporter's JSON state (`ReporterState`: last reported versions and key status, last failure, latest key check with the key's SHA-256 fingerprint, never the key; the same JSON the SDK 0.2 reporter wrote, so the switch to Plugin Health sent no new `installed` report) is in `user/data/supertext-translation/reporter.json` (`user-data://`), written atomically by `StateStore`; a missing or broken file is the empty state ("never reported").

**Ping:** `PingController` answers Plugin Health's `InstallationReporter::pingResponse()` as it is (status, `Content-Type: application/json`, `Cache-Control: no-store`, body); 404 while reports are off. The route is registered with the other routes and made public with `onApiCollectPublicRoutes` (`GET /api/v1/supertext/ping` only), so the API plugin skips authentication for it. API responses aren't part of Grav's page cache.

**Settings page:** `blueprints.yaml` has the toggle `version_reports` (default on) and the read-only field `supertext_status` (type `supertext-status`, `validate.ignore`; `onAdminSave` also drops it, because the API plugin keeps unknown keys). The field shows the latest check with the Commando Center's messages and links, and a note when `SUPERTEXT_VERSION_REPORTS` overrides the toggle.

For local testing, point the reports at the stand-in: `SUPERTEXT_REPORT_URL=http://127.0.0.1:8089/api/v1/report` (it answers 202 and logs the event). Never test against the live center.

## Local setup

```bash
git clone https://github.com/Supertext/Grav-Supertext-Translation.git
cd Grav-Supertext-Translation
composer install          # PHPUnit and symfony/yaml for the tests only
composer test
```

To run it in a Grav site, download Grav + Admin (<https://github.com/getgrav/grav/releases>, `grav-admin-v<version>.zip`), symlink or copy this folder to `user/plugins/supertext-translation`, add a second language and start it:

```bash
php -S 127.0.0.1:8080 system/router.php
```

**Stand-in API.** `scripts/stand-in-api/router.php` answers like the Supertext file API and returns real German and French translations of the demo's sample pages (`translations.php`; unknown text is returned unchanged and logged). Point the plugin at it with environment variables, which win over the settings and are meant for testing only:

```bash
php -S 127.0.0.1:8089 scripts/stand-in-api/router.php &
SUPERTEXT_API_KEY=stand-in SUPERTEXT_API_URL=http://127.0.0.1:8089/v1/ \
  SUPERTEXT_REPORT_URL=http://127.0.0.1:8089/api/v1/report php -S 127.0.0.1:8080 system/router.php
```

The stand-in also answers the Commando Center's reports (`POST /api/v1/report`, 202), so nothing is reported to Supertext from a local site.

## Tests

`composer test` runs PHPUnit (`tests/`). `FakeSupertext` is an in-memory stand-in for the file API, plugged into the SDK as its `HttpTransport`. The SDK's own protocol tests live in its repository.

- `MarkdownTest`: block splitting, round trips, formatting mapping, protected tokens, escaping.
- `SupertextClientTest`: the plugin's use of the SDK: every language submitted before waiting, the request fields the SDK sends, per-language failures, a missing or refused key, cleanup, every SDK error code mapped to a translatable reason, and Translation review (a review language's upload, status, download and delete go to the review service with the same key and User-Agent, the others directly; the default is Supertext's review service; an empty `review_url` or the API's own URL means no second client).
- `LanguageCodesTest`: Grav codes through the settings and the SDK's `Languages` (mapping wins, exact codes, the SDK's defaults (`de` → `de-DE`, `es-ES` → `es`), languages without one are `unsupported_language`, sources in their source form, formal/informal only where Supertext offers it, the settings from YAML, *Translation review* stored with the language (`{code, review}`), sanitized on save, off with an empty `review_url`, `SUPERTEXT_REVIEW_URL`).
- `PageTranslatorTest`: creating, states (missing/current/outdated/edited/manual), keeping published state, overwrite protection, an unmapped target (not sent, the others translated) and source (nothing sent), formal address per target, settings, a language with *Translation review* through the review service (all four calls) while the other goes directly.
- `FileExchangeTest`: translation files on a real page folder: the reference format round trip, the export's keys and languages (unmapped languages with their Grav code), HTML only for one language, nothing to export, an import creating an unpublished translation without API calls, `source_changed` only with the option, `untranslated`/`invalid_markup`/`missing` not written, a translation edited by hand kept unless `overwrite`, other plugins, unknown references and loading errors refused, files over the size limit refused.
- `ReportingTest` (with `FakeCenter`, a fake transport for the key check and the report): installed/settings_saved after a save, nothing or `upgraded` on requests, a changed key, 401/403 from a translation, *Check again*, reports off (key still checked), missing key, an unreachable center and the hour's wait, work queued until after the response, broken or unwritable state files, the key never stored or sent, the ping answers, the setting and `SUPERTEXT_VERSION_REPORTS`, the SDK version in the report, and a state file written by the SDK 0.2 reporter kept as it is (no new `installed`).
- `LanguagesTest`: `languages.yaml` complete in English, German, French and Italian, and every key used by the settings, the panel, the fields and the PHP code present.

Also checked by hand (and by the screenshot script) in a real Grav 2.2.4 with Admin2: the panel loads, the editor account can translate, a save in Admin2 of an unchanged translation keeps it *current*, an edit makes it *edited*; the ping route answers without login; a save of the plugin settings in Admin2 reports `settings_saved` to the stand-in, refreshes the key check and doesn't write `supertext_status`; an unmapped language is refused with *has no Supertext language yet* while the others are translated.

CI (`.github/workflows/ci.yml`) runs the tests on PHP 8.3 and 8.4, lints all PHP files, syntax-checks the panel and field scripts and runs PHPStan (see *Code quality and security checks*).

## Screenshots

The guides' screenshots come from the demo and are regenerated with:

```bash
pip install playwright && playwright install chromium   # once
composer docs:screenshots
```

`scripts/docs-screenshots.sh` downloads Grav from its GitHub release (cached in `.cache/`, or in `CACHE_DIR`), builds a throwaway site with the demo's entrypoint (random local-only passwords), starts the stand-in API (which also takes the version reports, `SUPERTEXT_REPORT_URL`) and Grav, and runs `scripts/screenshots.py` (Playwright, 1280×820 at 1× scale, cropped). It fails if any sample text had no stand-in translation. Regenerate the images in the same commit whenever the UI they show changes; add new sample text to `scripts/stand-in-api/translations.php`.

## Demo

The demo runs on Railway (project `supertext-cms-demos-php`, service `Grav`, region Amsterdam, 1 GB volume `grav-data`), built from `demo/Dockerfile` with the repository root as build context. Pushes to `main` deploy automatically (Railway's GitHub app has access to this repository via the Supertext organisation's installations).

- **URL:** https://grav-production.up.railway.app, admin at `/admin`
- **Healthcheck:** `/en`.
- **Image:** `php:8.3-apache` (only the prefork MPM is loaded at start, see the entrypoint), Grav + Admin2 2.2.4 from its GitHub release, the plugin copied from this repository.
- **Volume** at `/data`: `user/pages`, `user/accounts`, `user/config` and `user/data` are symlinked there. Grav core, plugins and themes always come from the image, so a deploy updates the plugin.
- **On every start** (`demo/entrypoint.sh`): seeds the sample pages on a fresh volume, merges the languages (`en` source, `de`, `fr`) into `system.yaml` (`demo/configure.php`), writes the plugin settings only if there are none yet (`de` → `de-CH`, `fr` → `fr-CH`), creates the demo accounts (`demo/seed-accounts.php`), then starts Apache on `$PORT`.

### Variables (Railway → service → Variables)

| Variable | Purpose |
| --- | --- |
| `SUPERTEXT_API_KEY` | Supertext API key (prefix optional) |
| `SUPERTEXT_VERSION_REPORTS` | Optional: `0` turns the version reports to the Commando Center off (default on); overrides the setting |
| `SUPERTEXT_REVIEW_URL` | Optional: another review service than Supertext's for languages with *Translation review* on (`review_url`; empty keeps the setting) |
| `DEMO_ADMIN_EMAIL`, `DEMO_ADMIN_PASSWORD` | Full administrator (`api.super`), for Supertext staff |
| `DEMO_EDITOR_EMAIL`, `DEMO_EDITOR_PASSWORD` | Editor: may edit pages and media in every language and translate (`api.access`, `api.pages.read/write`, `api.media.read/write`, `api.system.read`); used for tests and screenshots |
| `PORT` | Set by Railway |

The accounts are created on every start if no account with that email exists; existing accounts are never changed (no password resets). The username is the part of the email before the `@`. A password that breaks Grav's rule (8+ characters with a number, upper- and lower-case letter) skips that account with a warning in the log; the demo still starts. Only variable names are logged. Because the accounts exist, Admin2's first-run "create administrator" screen does not appear once `DEMO_*` is set. See `demo/.env.example`.

Grav has an editor role only as permissions, not as a named role; the editor account gets exactly the page and media permissions listed above. Languages have no separate access rights in Grav, so page write access covers every language.

## Code quality and security checks

- **Checks** (`.github/workflows/checks.yml`): on every push and pull request, [actionlint](https://github.com/rhysd/actionlint) and [zizmor](https://docs.zizmor.sh) lint the workflows. On pull requests, dependency review fails a PR that adds a package with a known vulnerability (moderate or worse). Third-party actions are pinned to commit SHAs (Dependabot keeps them current); checkouts don't keep credentials, and workflows get `contents: read` unless a job needs more (the release job: `contents: write`).
- **Links** (`.github/workflows/links.yml`): [lychee](https://lychee.cli.rs) checks the links in all Markdown files weekly and whenever docs change on `main`. Broken links open or update the issue "Broken links in the docs" (a docs push that breaks links also fails). Links that can't work from CI go in `.lycheeignore` (one regex per line).
- **PHPStan** (job `phpstan` in `ci.yml`, config `phpstan.neon`): level 5 on the plugin's own code (`supertext-translation.php`, `classes/`), not the tests, `demo/` or the bundled SDK (`lib/`, checked in its own repository). Grav isn't a Composer package, so the job unpacks the same Grav + Admin2 release as the demo into `.grav/` (git-ignored); PHPStan reads Grav core and the API plugin's classes from there. Locally:

  ```bash
  mkdir .grav && curl -fsSL https://github.com/getgrav/grav/releases/download/2.2.4/grav-admin-v2.2.4.zip -o .grav/grav.zip && unzip -q .grav/grav.zip -d .grav
  phpstan analyse            # or: php phpstan.phar analyse
  ```

  Existing findings that aren't simple to fix are listed in `phpstan-baseline.neon` (regenerate with `phpstan analyse --generate-baseline phpstan-baseline.neon` after fixing one). New code must not add findings.
- **GitHub settings** (set by Remy's setup script, not in the repo): secret scanning with push protection (a push containing a known token format is rejected; findings under *Security → Secret scanning*) and CodeQL default setup (findings under *Security → Code scanning* and as PR comments). CodeQL doesn't cover PHP, which is why this repo runs PHPStan.
- `.github/workflows/support-triage.yml`: support requests from the Supertext integrations website arrive as issues labelled `support` (contact details stay in the Supertext CMS). Claude classifies each one: answers questions (marked `<!-- supertext:reply -->`, emailed to the sender by the CMS) and closes them, fixes bugs as pull requests for review (never merged automatically), labels feature requests `feature-request`. Only people with triage rights can add labels, so outsiders can't start it. Needs the organisation secret `ANTHROPIC_API_KEY`; the workflow is the same in every Supertext repo.

Before starting work in this repo, look at its open findings: code scanning alerts, secret scanning alerts, Dependabot PRs and the "Broken links in the docs" issue.

## Releasing

Releases are published by `.github/workflows/release.yml` when the version is officially bumped; nobody tags or creates releases by hand.

1. Check that `composer test` passes and the screenshots are current.
2. Move the *Unreleased* entries in `CHANGELOG.md` under a new `## X.Y.Z — YYYY-MM-DD` section, and keep an empty *Unreleased* above it.
3. Set the same version in:
   - `blueprints.yaml`: `version`, shown in the admin's plugin list
4. Push to `main`. The workflow checks that the version files match `CHANGELOG.md`, then tags `vX.Y.Z` and creates the GitHub release with the CHANGELOG section as notes (0.x versions as pre-releases). A push that adds no new version does nothing, and a version that is already released is skipped. After fixing a failed run, start it again with *Run workflow* on the *Release* workflow.

The release attaches `supertext-translation-X.Y.Z.zip`, which unpacks into a folder named `supertext-translation`.
To list the plugin in GPM later, follow the plugin submission instructions in the Grav documentation (<https://learn.getgrav.org>).
## Roadmap / known limitations

- Translating a whole page tree or several pages at once (a "bulk translate" page in Admin2).
- Translation of Flex objects and site/config strings.
- Image alt texts in Markdown and media metadata (`.meta.yaml`).
- The settings page shows the API key check result after a save by reloading it a few seconds later (Admin2 has no "saved" event for plugin fields).
- No translation memory or change detection of its own: a translation memory and reviewers' corrections come from the review service when a language has *Translation review* on; corrections reach Grav only with the next translation (no push).
- Asynchronous jobs for very long pages (today the request waits for Supertext, up to *Maximum wait*).
- GPM listing.
