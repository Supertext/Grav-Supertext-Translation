# Developer guide

Architecture, the Supertext protocol, local setup, tests, the demo and releasing.

## Architecture

Grav 2 replaced its classic admin with **Admin2**, a single-page app that talks to Grav only through the **API plugin**. Admin2 does not fire the old `onAdmin*` page-editor events, so the plugin hooks into the extension points of the API plugin:

| Piece | File | What it does |
| --- | --- | --- |
| Plugin class | `supertext-translation.php` | Registers the class autoloader, the API routes (`onApiRegisterRoutes`) and the page-editor panel (`onApiContextPanels`). |
| Panel | `admin-next/panels/supertext-translation.js` | A custom element that Admin2 loads from `GET /api/v1/gpm/plugins/supertext-translation/panel-script` when the toolbar button is clicked. Admin2 sets its `route`, `lang` and `type` attributes; it fires `close` and `badge`. It calls the API with the user's token (`X-API-Token`). |
| API controller | `classes/Controller/TranslationController.php` | `GET /api/v1/supertext/status?route=…` and `POST /api/v1/supertext/translate` (`{route, languages, overwrite}`). Extends the API plugin's `AbstractApiController`, so authentication, page ACL (`authorizePageAction(…, 'update', 'api.pages.write')`), error format (RFC 7807) and cache invalidation headers work like the core routes. |
| Translator | `classes/PageTranslator.php` | Reads the source file, builds one HTML document, sends it to Supertext for all target languages, writes `<template>.<lang>.md`, tracks states. Independent of Grav (testable). |
| Markdown | `classes/Markdown/MarkdownDocument.php`, `InlineConverter.php` | Splits the Markdown body into blocks and back; converts inline Markdown to HTML and back. |
| Supertext client | `classes/Api/SupertextClient.php`, `HtmlDocument.php`, `CurlTransport.php` | Protocol, retries, HTML packing. |
| Settings | `classes/Settings.php`, `blueprints.yaml`, `supertext-translation.yaml` | Settings with defaults, the Admin2 form, defaults file. |
| Strings | `languages.yaml`, `classes/Messages.php` | All UI strings in English, German, French and Italian (see *Interface languages* below). `Messages` resolves the admin user's language (the API plugin's `PreferencesResolver`, as its own controllers do) and translates the panel label and the API's messages. |

### Interface languages

Everything the plugin shows is in `languages.yaml`, in `en`, `de`, `fr` and `it`:

- `PLUGIN_SUPERTEXT_TRANSLATION.SETTINGS.*`: the settings form. `blueprints.yaml` holds only these keys; Admin2 translates them into the user's admin language.
- `PLUGIN_SUPERTEXT_TRANSLATION.PANEL_LABEL` and `PLUGIN_SUPERTEXT_TRANSLATION.ERRORS.*`: the panel's toolbar label and the messages of the API routes (Grav strings, `%s`/`%d` placeholders). The Supertext client and `PageTranslator` stay free of Grav: they throw `SupertextException` (or return results) with an English message and a `reason` (e.g. `limit_exceeded`, args, detail), and `Messages` shows `ERRORS.<REASON>` instead.
- `ICU.PLUGIN_SUPERTEXT_TRANSLATION.PANEL.*`: the panel, read with Admin2's `window.__GRAV_I18N.t()` (ICU MessageFormat, `{name}` placeholders; use `’`, not `'`, in these strings). The panel script keeps the English strings as `EN` for an Admin2 without `__GRAV_I18N`.

New or changed strings need all four languages in the same commit, with formal address (Sie, vous, Lei) and Grav's own terms. `tests/LanguagesTest.php` checks that all languages have the same keys and placeholders, that every key used by `blueprints.yaml`, the panel and the PHP code exists, and that the panel's `EN` matches the English strings.

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

Each block (paragraph, heading, list item, table cell, HTML block line) becomes **one** element with `data-st-id`. Inline formatting is sent as HTML tags inside it (`<b>`, `<i>`, `<s>`, `<a href title>`), so Supertext translates whole sentences and can move the formatting with the words. The translated HTML is converted back to Markdown (`**`, `*`, `~~`, `[text](url "title")`).

Protected tokens are replaced before sending and restored from the source afterwards, whatever Supertext does with them:

- `<code data-k="N">text</code>` for code spans and autolinks (text visible for context),
- `<img data-k="N" alt="">` for images, Twig and similar.

Kept verbatim (never sent): fenced and indented code, Twig tag lines, shortcode tag lines, link reference definitions, rules, table separators, GitHub alert markers, lines without letters. Text that starts like block syntax after translation (`# `, `- `, `1. `, `>`, `|`) is escaped.

Known simplifications: soft-wrapped paragraph lines are joined into one line; `_emphasis_` is written back as `*emphasis*`; image alt text in Markdown and Twig inside text are not translated; modular sections and child pages are separate pages.

## Supertext API protocol

AI file translation API v1, same as the WordPress, TYPO3, Strapi and Payload plugins.

| Step | Request |
| --- | --- |
| Check key (free) | `GET https://api.supertext.com/v1/features` |
| Submit | `POST /v1/translate/ai/file` multipart: `file` (Content-Type exactly `text/html`, no charset), `target_lang` (e.g. `de-CH`), `source_lang` (primary subtag only, `en`), optional `politeness` (`more`/`less`) → `{"file_id": …}` |
| Poll | `GET /v1/translate/ai/file/{id}/status` → `in_progress`, `done`, `error`, `limit_exceeded`, `deleted` |
| Download | `GET /v1/translate/ai/file/{id}/translation` → translated HTML |
| Clean up | `DELETE /v1/translate/ai/file/{id}` (always, also after errors) |

Lessons applied from the live API (October 2026):

- Header `Authorization: Supertext-Auth-Key <key>`; exactly one prefix. A pasted prefix is stripped (`SupertextClient::normalizeKey`).
- Rate limit per key and second (HTTP 429): retried up to 4 times, honouring `Retry-After`, else 1/2/4/8 s with jitter.
- All target languages are submitted first, then polled together, so three languages take about as long as one. One failing language doesn't stop the others.
- Documents over 900,000 characters are refused before sending.

Verified against the live API on 2026-10-05: the demo's About page into `de-CH` and `fr-CH` in 7 to 9 s (locally and on the Railway demo), with bold text moved correctly inside the translated sentence.

## Local setup

```bash
git clone https://github.com/Supertext/Grav-Supertext-Translation.git
cd Grav-Supertext-Translation
composer install          # PHPUnit and symfony/yaml for the tests only
composer test
```

To run it in a Grav site, download Grav + Admin (<https://getgrav.org/download/core/grav-admin/latest>), symlink or copy this folder to `user/plugins/supertext-translation`, add a second language and start it:

```bash
php -S 127.0.0.1:8080 system/router.php
```

**Stand-in API.** `scripts/stand-in-api/router.php` answers like the Supertext file API and returns real German and French translations of the demo's sample pages (`translations.php`; unknown text is returned unchanged and logged). Point the plugin at it with environment variables, which win over the settings and are meant for testing only:

```bash
php -S 127.0.0.1:8089 scripts/stand-in-api/router.php &
SUPERTEXT_API_KEY=stand-in SUPERTEXT_API_URL=http://127.0.0.1:8089/v1/ php -S 127.0.0.1:8080 system/router.php
```

## Tests

`composer test` runs PHPUnit (`tests/`). `FakeSupertext` is an in-memory stand-in for the file API.

- `MarkdownTest`: block splitting, round trips, formatting mapping, protected tokens, escaping.
- `SupertextClientTest`: protocol order, multipart fields, auth header, 429 retries, error messages, per-language failures, cleanup.
- `PageTranslatorTest`: creating, states (missing/current/outdated/edited/manual), keeping published state, overwrite protection, settings.
- `LanguagesTest`: `languages.yaml` complete in English, German, French and Italian, and every key used by the settings, the panel and the PHP code present.

Also checked by hand (and by the screenshot script) in a real Grav 2.2.4 with Admin2: the panel loads, the editor account can translate, a save in Admin2 of an unchanged translation keeps it *current*, an edit makes it *edited*.

CI (`.github/workflows/ci.yml`) runs the tests on PHP 8.3 and 8.4, lints all PHP files and syntax-checks the panel script.

## Screenshots

The guides' screenshots come from the demo and are regenerated with:

```bash
pip install playwright && playwright install chromium   # once
composer docs:screenshots
```

`scripts/docs-screenshots.sh` downloads Grav (cached in `.cache/`), builds a throwaway site with the demo's entrypoint (random local-only passwords), starts the stand-in API and Grav, and runs `scripts/screenshots.py` (Playwright, 1280×820 at 1× scale, cropped). It fails if any sample text had no stand-in translation. Regenerate the images in the same commit whenever the UI they show changes; add new sample text to `scripts/stand-in-api/translations.php`.

## Demo

The demo runs on Railway (project `supertext-cms-demos-php`, service `Grav`, region Amsterdam, 1 GB volume `grav-data`), built from `demo/Dockerfile` with the repository root as build context. Pushes to `main` deploy automatically (Railway's GitHub app has access to this repository via the Supertext organisation's installations).

- **URL:** https://grav-production.up.railway.app, admin at `/admin`
- **Healthcheck:** `/en`.
- **Image:** `php:8.3-apache` (only the prefork MPM is loaded at start, see the entrypoint), Grav + Admin2 2.2.4 from getgrav.org, the plugin copied from this repository.
- **Volume** at `/data`: `user/pages`, `user/accounts`, `user/config` and `user/data` are symlinked there. Grav core, plugins and themes always come from the image, so a deploy updates the plugin.
- **On every start** (`demo/entrypoint.sh`): seeds the sample pages on a fresh volume, merges the languages (`en` source, `de`, `fr`) into `system.yaml` (`demo/configure.php`), writes the plugin settings only if there are none yet (`de` → `de-CH`, `fr` → `fr-CH`), creates the demo accounts (`demo/seed-accounts.php`), then starts Apache on `$PORT`.

### Variables (Railway → service → Variables)

| Variable | Purpose |
| --- | --- |
| `SUPERTEXT_API_KEY` | Supertext API key (prefix optional) |
| `DEMO_ADMIN_EMAIL`, `DEMO_ADMIN_PASSWORD` | Full administrator (`api.super`), for Supertext staff |
| `DEMO_EDITOR_EMAIL`, `DEMO_EDITOR_PASSWORD` | Editor: may edit pages and media in every language and translate (`api.access`, `api.pages.read/write`, `api.media.read/write`, `api.system.read`); used for tests and screenshots |
| `PORT` | Set by Railway |

The accounts are created on every start if no account with that email exists; existing accounts are never changed (no password resets). The username is the part of the email before the `@`. A password that breaks Grav's rule (8+ characters with a number, upper- and lower-case letter) skips that account with a warning in the log; the demo still starts. Only variable names are logged. Because the accounts exist, Admin2's first-run "create administrator" screen does not appear once `DEMO_*` is set. See `demo/.env.example`.

Grav has an editor role only as permissions, not as a named role; the editor account gets exactly the page and media permissions listed above. Languages have no separate access rights in Grav, so page write access covers every language.

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
- Asynchronous jobs for very long pages (today the request waits for Supertext, up to *Maximum wait*).
- GPM listing.
