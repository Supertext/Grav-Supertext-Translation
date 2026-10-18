# User guide: translating pages with Supertext in Grav

This guide is for editors. It explains how to translate a page into your site's other languages from the Grav admin, how to review the result, and what the messages mean.

You need an account that can edit pages. If you don't see the Supertext button described below, ask your administrator.

The Supertext panel and its messages appear in your admin language (English, German, French or Italian).

## Translate a page

1. In the admin, open **Pages** and click the page you want to translate. Make sure you are on the page in the source language (usually English, shown as **EN** next to the title).
2. In the toolbar at the top right, click the **Supertext translation** button. It is the first icon, showing the letters A and 文:

   ![The page editor toolbar with the Supertext translation button highlighted as the first icon](images/editor-toolbar.png)

3. A panel opens on the right. It lists every other language of your site and whether the page is already translated. Languages that are not translated yet, or whose translation is out of date, are ticked for you.

   ![The Supertext panel listing German (de-CH) and French (fr-CH), both "Not translated yet" and ticked, with a "Translate into 2 languages" button](images/panel-before.png)

4. Tick the languages you want and click **Translate into … languages**. Supertext translates all chosen languages at the same time. This usually takes 10 to 60 seconds, depending on the length of the page.

   ![The panel while translating: the button shows a spinner and "Translating…"](images/panel-translating.png)

5. When it is done, the panel shows a result for each language, and the languages show **Up to date**.

   ![The panel after translating: "Deutsch: translation created", "Français: translation created", both languages "Up to date"](images/panel-after.png)

You can close the panel with **×** at any time. Closing it does not stop a translation that is already running.

Next to each language the panel shows the Supertext language it is translated into, e.g. *de-CH* for Swiss German. Languages without a choice in the plugin settings use Supertext's default (e.g. *de-DE* for `de`, *en-US* for `en`). A language marked **not mapped** has no default either (e.g. `gsw`): it can't be ticked, and a note at the top names it. Your administrator chooses the Supertext language for it in the plugin settings.

## Review and publish a translation

New translations are saved **unpublished**, so visitors don't see them until you have checked them (your administrator can change this).

1. In the page editor, use the **Translations** box on the right (or the language menu next to **Save**) and choose the language, e.g. **Deutsch**.
2. The editor now shows the translated page. Check the text, correct it if needed, and click **Save**.
3. To publish it, open the **Options** tab and set **Published** to yes, then **Save**.

![The German translation open in the page editor: title "Willkommen bei Supertext für Grav", the translated Markdown, and "Published: No" in the Page Info box](images/translated-editor.png)

Once published, visitors see the page at the language's address, e.g. `/de/` for German:

![The published German page on the website with the translated heading, text, list and menu label "Startseite"](images/translated-site.png)

Good to know:

- Grav's **Translations** box lists an unpublished translation under *Not translated* as well. That is Grav's way of saying visitors can't see it yet; it disappears from that list once you publish it. Don't use *Create …* there for a language Supertext already translated.
- If you had the translated language open in the editor while translating it again, switch to another language and back to see the new text.
- Publishing a translation, or changing its options, does not count as editing it.

## Translate again after the source changes

When you change the English page, its translations are shown as **Source changed since**. They are ticked automatically; click **Translate** to update them. An updated translation keeps its published state and its options.

## Your own changes are protected

If someone edited a translation after Supertext made it, the panel shows **Edited after translation**. If a translation exists that was not made with Supertext, it shows **Exists, not from Supertext**.

These languages are never replaced without asking. When you tick one and click **Translate**, the panel asks first:

![The panel asking "Replace existing translation?" because "Deutsch was edited after it was translated", with Replace and Cancel buttons](images/panel-overwrite-warning.png)

- **Replace** translates the page again and replaces the edited text.
- **Cancel** keeps the translation as it is.

## Translate with a file (XLIFF or HTML)

Instead of translating with Supertext's API, you can download a page's texts as a file, have them translated elsewhere (a translator, a translation tool such as Trados, memoQ or Phrase, or Supertext's web app) and import the translated file again. The translation is written exactly like a translation with Supertext: as the language file of the page, new ones unpublished.

**Export for translation:** open the page in the source language and the Supertext panel, then open **Export for translation** at the bottom of the panel.

1. Choose the **File format**:
   - **XLIFF 1.2 (for translation tools)**: the better choice for translators and translation tools; bold text, links and code appear as protected tags. One file can hold several languages.
   - **HTML (one language per file)**: for Supertext's web app or anyone who translates HTML. Tick exactly one language.
2. Tick the languages and click **Download file**. The file is called e.g. `grav-blog-hello-de.xlf` (the page route and the language; without the language for several languages).

The file holds the same texts a translation with Supertext would send: the page content and the translated front matter fields (title, menu label, meta description). A page without texts gives *Nothing to translate.*

**Send it for translation.** Translators must keep the file's structure: in an HTML file the head (the `supertext:` meta tags) and the `data-st-id` attributes must stay as they are. An HTML file can't mark a text as untranslated: imported unchanged, it writes the source text into the translation.

**Import translation:** open the Supertext panel of any page (the file says which page and language it belongs to), open **Import translation**, choose the **Translated file (XLIFF or HTML)** and click **Import translation**. Files up to 10 MB.

- **Also import texts whose source changed since the export** (off by default): texts edited in the source after the export are skipped unless you tick this.
- **Replace translations edited by hand or not made with Supertext** (off by default): like translating with Supertext, such translations are kept (*It was kept; confirm to replace it.*) unless you tick this.

The panel then shows, per page and language:

| Line | Meaning |
| --- | --- |
| *Imported: n* | Texts written into the translation. |
| *Skipped, source changed since the export: n* | The source text was edited after the export; tick the option above to import them anyway, or export again. |
| *Not translated in the file: n* | The file has no translation for these texts (XLIFF). |
| *Formatting changed, not imported: n* | The translation broke or added formatting (tags); fix it in the file or translate these texts again. |
| *Not in the file: n* | Texts added since the export; translate them with Supertext or export again. |

Grav keeps a translation as one file per language, so texts that weren't imported are written in the source language; translate the page again or import a complete file to fill them. Errors: *This file isn't a valid XLIFF or HTML translation file*, *This file wasn't exported by the Supertext plugin, or its header was removed*, *The file is too large (at most 10 MB)*, *This file belongs to another system (…)* (exported by another Supertext plugin), *The page this file was exported from no longer exists* (also when it was moved: its route changed), *The site has no language …*, *You are not allowed to edit this page.*

## What is translated

Translated:

- The page content (Markdown): headings, paragraphs, lists, block quotes, tables and the text inside HTML blocks. Bold, italic, strikethrough and links stay where they belong in the translated sentence.
- The title, the menu label and the meta description. Your administrator can choose other fields.

Kept exactly as written:

- Code (`` `inline` `` and code blocks), images and their file names, link addresses
- Twig (`{{ … }}`, `{% … %}`), shortcode tags (`[notice]`), GitHub alert markers (`> [!TIP]`)
- Taxonomy, dates, template and all other page options

Not handled by the plugin:

- Media files are shared by all languages of a page in Grav; they are not copied or translated.
- Child pages and modular sections are separate pages: translate each one on its own.
- Image alt texts written inside Markdown (`![alt](…)`) are not translated.

## Messages

The messages appear in your admin language; the table lists their English wording.

| Message | What it means and what to do |
| --- | --- |
| No Supertext API key is configured yet | The administrator still has to enter the Supertext API key. Translating is disabled until then. The message links to Supertext's signup page and to the page where the key is generated (supertext.com → Integrations → API, requires the Admin role). |
| Supertext refused the API key | The key is wrong or no longer valid. Ask your administrator. |
| No Supertext language is chosen yet for: … / The language … has no Supertext language yet | The administrator hasn't said which Supertext language this site language is (for example Swiss or German German). Nothing was sent for it; the other languages are translated. Ask your administrator to choose it under *Plugins → Supertext Translation → Supertext language codes*. |
| Supertext offers no formal or informal tone for this language | The plugin settings ask for formal or informal address, and Supertext doesn't offer that for this language. Ask your administrator to set the form of address to *Supertext default*. |
| Supertext does not translate from … into … | Supertext doesn't offer this language pair, or the language code for Supertext is set wrongly. Ask your administrator to check the Supertext language codes. |
| Your Supertext translation limit is exceeded | Your Supertext plan's limit is reached. Contact Supertext or your administrator. |
| Supertext is busy (too many requests) | Wait a moment and try again. |
| Supertext took too long to answer | The translation took longer than the configured maximum. Try again; very long pages take longer. |
| The page is too long to translate in one go | Split the page into smaller pages. |
| This page has no EN version to translate from | The page only exists in other languages. Create the source-language version first. |
| … text blocks came back untranslated | Supertext returned the page without some blocks. They were kept in the source language; translate again or fix them by hand. |
| Page permissions deny 'update' on this page | You are not allowed to edit this page. Ask your administrator. |
