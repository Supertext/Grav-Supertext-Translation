# Changelog

## Unreleased

### Added

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
