# Installation and configuration

This guide is for administrators of a Grav site. It covers installing the plugin, connecting it to Supertext, setting up languages and every setting.

## Requirements

- Grav **2.0 or later** (tested with Grav 2.2.14)
- The **Admin2** and **API** plugins (bundled with the "Grav + Admin" download; Admin2 ≥ 2.1, API ≥ 1.0.44)
- PHP 8.3 or later with the `curl`, `dom` and `mbstring` extensions (all standard)
- Outgoing HTTPS from the web server to `api.supertext.com` (and, for [Translation review](#translation-review), to Supertext's review service, `localizationreview-production.up.railway.app`)
- A Supertext account with an API key for AI translation (see [API key](#api-key))
- At least two site languages (see [Language setup](#language-setup))

The classic Grav 1.x admin is not supported: the plugin adds its button to the Admin2 page editor.

## Install

The plugin is not in the Grav package index (GPM) yet; install it from GitHub.

**From a release (recommended):**

1. Download `supertext-translation-<version>.zip` from the [Releases page](https://github.com/Supertext/Grav-Supertext-Translation/releases).
2. Unzip it into `user/plugins/`. It already contains the folder **`supertext-translation`** with `supertext-translation.php` inside.
3. In the admin, open **Plugins**: *Supertext Translation* is listed and enabled. If it isn't, enable it.

**From the main branch:** download the repository as a ZIP from <https://github.com/Supertext/Grav-Supertext-Translation> (*Code → Download ZIP*), unzip it into `user/plugins/` and rename the folder to **`supertext-translation`**, then enable it as above.

**With Git:**

```bash
cd user/plugins
git clone https://github.com/Supertext/Grav-Supertext-Translation.git supertext-translation
```

The plugin has no Composer dependencies to install; it uses the libraries Grav already ships.

### Update

Replace the `user/plugins/supertext-translation` folder with the new version (or `git pull` in it), then clear the cache (**Cache** button in the admin, or `bin/grav clear`). Your settings in `user/config/plugins/supertext-translation.yaml` are kept.

### Uninstall

Remove the plugin in **Plugins → Supertext Translation → Remove**, or delete `user/plugins/supertext-translation` and `user/config/plugins/supertext-translation.yaml`.

Translations already made stay as normal Grav language files (`default.de.md` etc.). They contain a small `supertext:` block in their front matter, which Grav ignores; you can leave it or delete it.

## API key

1. **Supertext account:** no account yet? [Create one at supertext.com](https://www.supertext.com/person/en/account/signin) (log in or create an account with your email address).
2. **API key:** generate it at [supertext.com → Integrations → API](https://www.supertext.com/en/integrations/api). This requires the **Admin** role in your Supertext account; if you don't have it, ask your Supertext account's administrator.

Supertext shows the key as `Supertext-Auth-Key …`; paste it with or without that prefix. The plugin settings show the same two links below the API key field.

You can store it in one of two places:

- **In the admin:** **Plugins → Supertext Translation → API key**, then **Save**. It is stored in `user/config/plugins/supertext-translation.yaml`.
- **As an environment variable** `SUPERTEXT_API_KEY` on the server. It is used when the setting is empty. This keeps the key out of the `user/` folder (and out of Git if you version it). With Apache and `mod_php`, pass it through with `PassEnv SUPERTEXT_API_KEY`.

Only users who can change plugin settings (super administrators) see or change the key. Editors never see it.

Every time you save the plugin settings, the plugin checks the key with Supertext (a cost-free request, nothing is translated or billed) and shows the result under **API key check**, with the time of the check. **Check again** runs the check right away, for example after you changed `SUPERTEXT_API_KEY`. A key from the environment is also checked automatically when it changes. An invalid key never stops the settings from being saved; see [Troubleshooting](#troubleshooting) for the messages.

## Language setup

Grav keeps each language of a page in its own file (`default.en.md`, `default.de.md`, …). Set up the languages in **Configuration → System → Languages**:

![Grav's system configuration, Languages section: Supported languages en, de, fr; default language en; Include default language: Yes; Include default language in file extension: Yes](images/grav-languages.png)

- **Supported:** all languages of the site, e.g. `en`, `de`, `fr`. The plugin offers every one of them except the source language.
- **Override Default Language:** the main language. By default it is also the language the plugin translates from.
- **Include default language in file extension:** recommended *Yes*, so source pages are saved as `default.en.md`. Pages saved as `default.md` (no language) also work as the source.
- **Content Language Fallback** (optional): what visitors see for a page that has no published translation yet. `de: [de, en]` shows the English page; `de: [de]` shows "not found".

The same settings in `user/config/system.yaml`:

```yaml
languages:
  supported: [en, de, fr]
  default_lang: en
  include_default_lang_file_extension: true
  content_fallback:
    de: [de, en]
    fr: [fr, en]
```

Grav's own theme has no language menu; visitors reach the languages under `/de/`, `/fr/` etc. Add a language switcher to your theme if you need one.

### Supertext languages

Supertext needs to know exactly which language variant each site language is: Swiss or German German, British or American English. Under **Plugins → Supertext Translation → Supertext language codes** every site language (the source language too) has a list of the languages Supertext offers, with the language's name in your admin language and its code:

![The Languages section of the plugin settings: source language en; Supertext language codes with one list per site language: English → American English (en-US), noted as the default for en and as the source language sent as en; Deutsch → Swiss High German (de-CH); Français → Swiss French (fr-CH); a Translation review checkbox under the German and French lists, not ticked](images/plugin-languages.png)

- Each list is preselected: with your saved choice, else the Supertext code the Grav code is exactly (`de-ch` → `de-CH`, `es` → `es`, `nl` → `nl`), else the default below. A row on its default says *Default for …*; choose another variant if you mean one, e.g. `de` → *German (Switzerland) (de-CH)* for Swiss German (no "ß").
- A language without a default shows **— choose —** and **Not mapped**; editors can't translate into it (the panel shows it greyed out, and translating gives *has no Supertext language yet*) until you choose one.
- The source language uses the same list: a source mapped to `de-CH` is sent as `de`. A bare source code such as `en` needs no choice.
- **Translation review**: a checkbox under each language's list (not for the source language), off by default. See [Translation review](#translation-review).
- Save the settings after changing a list. Only rows you changed are stored; the others keep following the preselection. When saving, the plugin keeps only codes Supertext offers.

Defaults (from the bundled Supertext SDK, used for every language you haven't chosen):

| Grav code | Default Supertext code |
| --- | --- |
| `de`, `en`, `fr`, `it`, `pt` | `de-DE`, `en-US`, `fr-FR`, `it-IT`, `pt-PT` |
| `zh`, `zh-cn`, `zh-sg` | `zh-Hans` |
| `zh-tw`, `zh-hk`, `zh-mo` | `zh-Hant` |
| `sr`, `sr-latn-rs` | `sr-Latn` (`sr-cyrl-rs` → `sr-Cyrl`) |
| A region of a language Supertext has without region | that language: `es-es` → `es`, `nl-nl` → `nl`, `nb-no` → `nb` |
| No default | `en-ie`, `de-li`, `fr-be`, `sr-rs`, `gsw`, `no`, …: choose one |

The languages Supertext offers (October 2026):

| Language | Supertext codes |
| --- | --- |
| German | `de-CH`, `de-DE`, `de-AT` |
| English | `en-GB`, `en-US` |
| French | `fr-CH`, `fr-FR` |
| Italian | `it-CH`, `it-IT` |
| Portuguese | `pt-BR`, `pt-PT` |
| Chinese | `zh-Hans` (Simplified), `zh-Hant` (Traditional) |
| Serbian | `sr-Latn` (Latin), `sr-Cyrl` (Cyrillic) |
| Swiss German | `gsw-u-sd-chzh` (Zurich), `gsw-u-sd-chbe` (Bern) |
| All others, without region | `bg`, `cs`, `da`, `el`, `es`, `fi`, `hr`, `hu`, `ja`, `ko`, `nb`, `nl`, `pl`, `rm`, `ru`, `sk`, `sl`, `sq`, `sv`, `tr` |

Supertext rejects other forms, e.g. `es-ES`, `sl-SI` or `zh-CN`. Formal and informal address (*Form of address*) exist for all of them except English, Romansh (`rm`), Albanian (`sq`), Serbian and Swiss German; those always get Supertext's default, and the language list says so next to them.

In YAML (for automated setups, or per environment in `user/<environment>/config/plugins/supertext-translation.yaml`, which Grav merges over the main file), the setting is a map of Grav codes to Supertext codes; an entry wins over the default, and the lists in the settings show it:

```yaml
language_codes:
  de: de-CH
  fr: fr-CH
  en: en-GB
```

A language with *Translation review* on is written with its code and `review: true` (`code: ''` keeps the preselection):

```yaml
language_codes:
  de: { code: de-CH, review: true }
  it: { code: '', review: true }
```

## Settings

All settings are in **Plugins → Supertext Translation**:

![The Supertext Translation plugin settings in Admin2, section Supertext account: API key with the account and API key links, API key check "API key checked: the connection to Supertext works." with its time and a Check again button, API endpoint https://api.supertext.com/v1/, and Send version information to Supertext set to Enabled](images/plugin-settings.png)

| Setting | YAML key | Default | What it does |
| --- | --- | --- | --- |
| Plugin status | `enabled` | enabled | Turns the plugin (button and API routes) on or off. |
| API key | `api_key` | empty | Your Supertext API key. Empty: the `SUPERTEXT_API_KEY` environment variable is used. |
| API key check | – | – | Shows the latest automatic check of the key (not a setting). **Check again** checks it now. |
| API endpoint | `api_url` | `https://api.supertext.com/v1/` | Only change it if Supertext tells you to. |
| Send version information to Supertext | `version_reports` | enabled | Sends the site URL and the versions of this plugin, Grav and PHP to Supertext when you save these settings and after an update, so Supertext support can see which version you run. No content, no API key and no personal data. See [Version information](#version-information-and-the-ping-address). The environment variable `SUPERTEXT_VERSION_REPORTS=0` turns it off (and `1` on) whatever the setting says. |
| Source language | `source_language` | empty | Grav language code the translations are made from. Empty: the site's default language. |
| Supertext language codes | `language_codes` | none (the preselection) | One list per site language: the Supertext language it is translated into and from, e.g. `de` → `de-CH` (Swiss German, no "ß"), `fr` → `fr-CH`. Preselected with the exact Supertext code or the default (`de` → `de-DE`, `en` → `en-US`, …). A language without a default (`en-ie`, `gsw`) can't be translated until you choose one. See [Supertext languages](#supertext-languages). |
| Translation review (per language, under its list) | `language_codes.<code>.review` | off | Translations into this language go through Supertext's review service. See [Translation review](#translation-review). |
| Form of address | `politeness` | Supertext default | *Formal* (Sie, vous) or *Informal* (du, tu), for the languages Supertext offers both for. Languages without the choice (English, Romansh, Albanian, Serbian, Swiss German) always get Supertext's default. |
| Front matter fields | `header_fields` | `title`, `menu`, `metadata.description` | Fields of the page header translated together with the content. Use dots for nested fields. Only text values are translated. |
| Publish new translations | `publish_new_translations` | off | Off: new translations are created with `published: false` so editors can review them. On: they are published right away. Updating an existing translation never changes whether it is published. |
| Status check interval | `poll_interval` | 2 | Seconds between checks while Supertext translates. |
| Maximum wait | `poll_timeout` | 300 | Seconds to wait for Supertext before giving up. Your web server and PHP must allow requests this long (`max_execution_time`, proxy timeouts). |
| Review service | `review_url` | Supertext's review service (`https://localizationreview-production.up.railway.app/v1/`) | Address of the review service for languages with *Translation review* on. Empty turns review off for every language. Only change it if Supertext gives you another address. The environment variable `SUPERTEXT_REVIEW_URL` (if not empty) wins over the setting. |

Example `user/config/plugins/supertext-translation.yaml`:

```yaml
enabled: true
api_key: ''            # or set SUPERTEXT_API_KEY
source_language: en
language_codes:
  de: de-CH
  fr: fr-CH
politeness: default
header_fields: [title, menu, metadata.description]
publish_new_translations: false
version_reports: true  # or SUPERTEXT_VERSION_REPORTS=0 to turn it off
```

## Translation review

With **Translation review**, people check the translations on the website itself and correct them there: customers or Supertext linguists open the original and the translated page side by side in Chrome with the *Supertext Localization Review* extension and fix texts in a side panel. Their corrections come back into Grav.

Turn it on per language that is translated **into**: **Plugins → Supertext Translation → Supertext language codes** → tick **Translation review** under that language's list (e.g. *Deutsch*) → **Save**. Untick it to turn it off again; nothing else changes.

- Translations into that language then go through Supertext's review service instead of directly to Supertext. It answers exactly like Supertext and passes everything on with your API key, so billing, languages, form of address and error messages stay the same. The other languages keep going directly to Supertext.
- The review service keeps every translation of your account. A text it has translated before gets the same translation again (without a new Supertext call), and once a reviewer has corrected it, the corrected text.
- **Corrections arrive with the next translation:** after a review, translate the pages into that language again (*Supertext* panel → *Translate*) to write the corrections into Grav. As always, a translation an editor has changed since is only replaced after confirmation.
- Reviewers get a review link for your site from Supertext; nothing to set up in Grav.
- The review service works with keys of the **live** Supertext API. If you point *API endpoint* (or `SUPERTEXT_API_URL`) at another Supertext environment, leave review off.
- *Export for translation* and *Import translation* don't use the review service. The automatic API key check (and *Check again*) still goes directly to Supertext, and so do the version reports.
- *Review service* (`review_url`) empty turns review off for every language; the checkboxes are then disabled.

## Version information and the ping address

So that Supertext support knows which versions are in use, the plugin sends a short report to Supertext (the Supertext Commando Center):

- **When:** after you save the plugin settings, and after an update of the plugin, Grav or PHP (noticed the next time someone opens the Supertext panel, translates or opens the plugin settings). It is sent after the page has been delivered, never slows down the admin, and a failed report is simply tried again an hour later.
- **What:** the site URL (as Grav knows it), the versions of the plugin, Grav, PHP and the bundled Supertext SDK, whether an API key is set, the result of the latest API key check (*ok*, *invalid*, *missing*, *unreachable*, *error*, with the HTTP status), and the ping address below. **Never** page content, the API key itself or personal data. (The key's SHA-256 fingerprint is kept on your server, in `user/data/supertext-translation/reporter.json`, only to notice that the key changed; it is never sent.)
- **Turn it off:** set **Send version information to Supertext** to *Disabled* and save, or set the environment variable `SUPERTEXT_VERSION_REPORTS=0` for the web server (with Apache and `mod_php`: `PassEnv SUPERTEXT_VERSION_REPORTS`). Then nothing is sent. The API key check on the settings page keeps working.

**Ping address.** So that Supertext can check whether your site can be reached from the internet, the plugin answers a public address:

```
https://<your site>/api/v1/supertext/ping?challenge=<random text>
```

It needs no login and answers only with `{"ok":true,"plugin":"Grav-Supertext-Translation","challenge":"…"}`: no versions, no settings, no content. While version information is off it answers HTTP 404. If you changed the API plugin's route (`plugins.api.route`), the address follows it. A firewall, maintenance mode or a login wall in front of `/api/` makes the site show as "not reachable" at Supertext; the plugin works either way.

## Who can translate

The Supertext button is shown to users who can read pages, and translating needs the right to **edit the page**: the account permission `api.pages.write` (*Pages API → Create/Update/Delete Pages*), or a page-level permission that allows *update* on that page. Super administrators can always translate.

An editor account that works out of the box:

```yaml
access:
  site: { login: true }
  api:
    access: true
    pages: { read: true, write: true }
    media: { read: true, write: true }
```

**Export for translation / Import translation** (XLIFF 1.2 or HTML files, in the Supertext panel) need the same right to edit the page; an import checks it for every page in the file. Uploads are limited to 10 MB (PHP's `upload_max_filesize` and `post_max_size` must allow that much, or larger files are refused earlier) and are only read in memory, never stored. There is nothing to configure, and no API key is needed for files.

## Interface languages

The panel, the settings and the plugin's messages are available in English, German, French and Italian. They follow each user's admin language in Admin2 (*Preferences → Admin Language*). Other languages show the English texts.

## Troubleshooting

| Problem | Cause and fix |
| --- | --- |
| No Supertext button in the page editor | The plugin is disabled, the browser still has the old admin loaded (reload the page), or the user lacks page permissions. The button only exists in Admin2, not in the classic admin. |
| "No API key is set yet" (API key check) | Enter a key and save, or set `SUPERTEXT_API_KEY` for the web server process. |
| "Supertext rejected the API key" (API key check) | The key is wrong, incomplete or revoked: copy it again, or generate a new one at [supertext.com → Integrations → API](https://www.supertext.com/en/integrations/api) (requires the Admin role). The settings are saved anyway. |
| "The API key couldn't be checked because Supertext can't be reached" | The server can't reach `api.supertext.com` (firewall, proxy, DNS) or Supertext is down. Press **Check again** later. |
| "The API key couldn't be checked: Supertext answered with an error (…)" | Supertext answered unexpectedly, e.g. HTTP 429 (too many requests). Press **Check again** later. |
| "Not mapped" next to a language, or "The language … has no Supertext language yet" | Supertext has no default for that Grav code (e.g. `en-ie`, `gsw`). Choose the Supertext language for it in its list under **Plugins → Supertext Translation → Supertext language codes** and save (see [Supertext languages](#supertext-languages)). |
| "Supertext offers no formal or informal tone for this language" | The language is mapped to a code the plugin doesn't know, and Supertext refused the form of address for it. Set **Form of address** to *Supertext default*, or map the language to one of the listed codes. |
| "No Supertext API key is configured" | Generate a key (see [API key](#api-key)) and enter it in the settings, or set `SUPERTEXT_API_KEY` for the web server process (not only for your shell). |
| "Supertext does not translate from … into …" | Supertext doesn't offer that language pair, or a *Supertext language codes* entry in the YAML file is wrong (e.g. `german` or `es-ES` instead of `de-CH` or `es`). Choose the language from the list in the settings. |
| "Supertext refused the API key" only for languages with *Translation review* | The review service only works with keys of the live Supertext API: with another *API endpoint* leave review off. |
| Reviewers' corrections don't show up in Grav | They arrive with the next translation: translate the pages into that language again. Check that the language's *Translation review* is ticked and saved. |
| "Supertext refused the API key" | Wrong or revoked key. Copy it again, or generate a new one, at [supertext.com → Integrations → API](https://www.supertext.com/en/integrations/api) (requires the Admin role). |
| "This site has only one language" | Add languages under **Configuration → System → Languages**. |
| Translations stop after about 30–60 seconds | A proxy or PHP limit ends the request first. Raise `max_execution_time` and the proxy/web-server timeout above *Maximum wait*. |
| "Could not reach Supertext" | The server can't make outgoing HTTPS requests to `api.supertext.com` (firewall, proxy, missing CA certificates). |
| "Cannot write default.de.md" | The web server user can't write to the page folder. Fix the folder permissions. |
| Visitors don't see the translation | New translations are unpublished. Publish them, and clear the cache if needed. |
| The ping address answers 404 | Version information is off (setting or `SUPERTEXT_VERSION_REPORTS=0`), or the plugin is disabled. |
| "The file is too large (at most 10 MB)" for a smaller file | PHP's `upload_max_filesize` or `post_max_size` is lower than the file; raise them. |
| "This file belongs to another system" / "This file wasn't exported by the Supertext plugin" | The file comes from another Supertext plugin, or the translation tool removed the file's header (HTML: the `supertext:` meta tags; XLIFF: the `<file>` attributes). Export again and keep them. |
| Errors in more detail | Failed translations are logged in `logs/grav.log` with the page and language. |
