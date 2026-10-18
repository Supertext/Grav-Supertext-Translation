# Changelog

## Unreleased

### Added

- Export for translation (XLIFF 1.2 or HTML) and import of translated files; Supertext SDK 0.5.0. In the Supertext panel of the page editor: *Export for translation* (file format, languages, *Download file*) downloads the page's texts (content and translated front matter fields); *Import translation* uploads the translated file (at most 10 MB, read in memory only) and writes the language file like a Supertext translation (new ones unpublished; translations edited by hand or not made with Supertext only with *Replace translations edited by hand or not made with Supertext*). Texts whose source changed since the export are skipped unless *Also import texts whose source changed since the export* is ticked; the panel lists imported, skipped, untranslated, broken-formatting and missing texts per page and language. Same right as translating (edit the page). In English, German, French and Italian.

### Changed

- **Default Supertext languages** (bundled Supertext SDK 0.4.0): a site language you haven't chosen a Supertext language for now uses its default instead of failing: `de` → `de-DE`, `en` → `en-US`, `fr` → `fr-FR`, `it` → `it-IT`, `pt` → `pt-PT`, `zh` → `zh-Hans`, `zh-tw` → `zh-Hant`, `es-es` → `es`. The lists under *Supertext language codes* are preselected with it (*Default for …*), name the languages in your admin language, and only languages without a default (`en-ie`, `de-li`, `gsw`, …) show *— choose —* and *Not mapped*. Saved choices are kept and still win; when saving, only codes Supertext offers are stored (an older list of rows is converted to the current form). If you want Swiss German or another variant, check the lists after updating.
- The plugin now talks to Supertext through the official Supertext SDK for PHP, bundled with the plugin (`lib/supertext-sdk`, nothing extra to install). Translating into several languages still runs in parallel. Requests carry the plugin version in the User-Agent.
- A language pair Supertext doesn't offer now gets its own message naming the two languages, in all four interface languages.
- Version reports, the API key check result and the reachability ping now use [Supertext Plugin Health](https://github.com/Supertext/Commando-Center-Supertext-Translation/tree/main/plugin-health) 0.1.0 (bundled in `lib/supertext-plugin-health`); Supertext SDK updated to 0.3.0. No change for sites: settings and stored state stay as they are.

### Fixed

- A target language Supertext doesn't know is now reported as an unsupported language pair naming the two languages (was: "Supertext could not find the requested file").
- Languages are sent to Supertext only as codes Supertext accepts (bundled Supertext SDK 0.2.1). The plugin no longer guesses a region: guessed codes such as `es-ES`, `sl-SI` or `zh-CN` were rejected by Supertext. A Grav code that is exactly a Supertext code (`de-ch` → `de-CH`, `es`) is used as it is; a bare `de`, `en`, `fr`, `it` or `pt` needs an entry in *Supertext language codes*. The source is always sent without region, from the same entries (`de` → `de-CH` is sent as `de`).
- Formal or informal address is only sent for languages Supertext offers it for; the others (English, Romansh, Albanian, Serbian, Swiss German) get Supertext's default instead of an error.

### Added

- *Supertext language codes* in the settings is now a list of the site's languages, each with a choice of the languages Supertext offers, pre-selected where the Grav code is exactly a Supertext code. Languages without a Supertext language are shown as *Not mapped* in the settings and *not mapped* in the panel, and translating into them gives a clear message ("The language … has no Supertext language yet") while the other languages are translated. **Check your settings after updating:** sites that relied on the automatic region for `de`, `fr`, `it`, `en` or `pt` need to choose it.
- Automatic API key check: every time the settings are saved (and with *Check again*), the plugin checks the key with Supertext and shows the result with its time on the settings page, with the account and API key links when the key is missing or rejected.
- Setting *Send version information to Supertext* (on by default): sends the site URL and the versions of the plugin, Grav, PHP and the Supertext SDK, whether a key is set and the key check result to Supertext when the settings are saved and after an update; never content, the API key or personal data. `SUPERTEXT_VERSION_REPORTS=0` turns it off. A public address, `/api/v1/supertext/ping`, lets Supertext check that the site can be reached (404 while the setting is off).
- French and Italian interface, and German, which was missing: the panel, the settings and the messages from Supertext now follow the user's admin language. The "no API key" and "API key refused" messages now also link to Supertext account sign-up.

## 0.1.0 — 2026-10-07

### Added

- Supertext translation panel in the Admin2 page editor: shows each site language with its state (not translated, up to date, source changed, edited, not from Supertext) and translates the page into the chosen languages at once.
- Translation of the Markdown content block by block, with formatting and links kept inside the sentence; code, images, Twig, shortcodes and alert markers kept as written.
- Translation of front matter fields (default: title, menu label, meta description; configurable).
- New translations are created unpublished (configurable); updates keep a translation's published state and options.
- Protection of edited and hand-made translations: they are only replaced after confirmation.
- Settings: API key (or `SUPERTEXT_API_KEY`), endpoint, source language, Supertext language codes per Grav language, form of address, translated fields, polling.
- API routes `GET /api/v1/supertext/status` and `POST /api/v1/supertext/translate`.
- Retries for the Supertext rate limit (HTTP 429), parallel translation into several languages.
- Railway demo with English, German and French, sample pages and `DEMO_ADMIN_*` / `DEMO_EDITOR_*` accounts.
- Links to create a Supertext account and to generate the API key (supertext.com → Integrations → API, Admin role required) in the API key setting, the panel's "no API key" message, API key error messages and the docs.
- Installation guide, user guide, developer guide with screenshots, and a script to regenerate them.
