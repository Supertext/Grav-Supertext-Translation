#!/usr/bin/env node
/**
 * End-to-end test against the REAL Supertext API: a fresh demo (nothing translated yet)
 * with a real SUPERTEXT_API_KEY. The editor opens every English page in Admin2 and
 * translates it into every other site language with the Supertext panel, then the page
 * files are checked (Grav has no database): every page has a translation file per
 * language, it is unpublished, the title, menu label, meta description and body are
 * filled and really in the target language (de-CH without "ß"), Markdown formatting,
 * links, tables, alerts and inline code survived, nothing of the HTML sent to Supertext
 * is left, and translating again updates the same files instead of adding new ones.
 * See "End-to-end tests" in docs/DEVELOPER.md.
 *
 *   GRAV_URL=http://127.0.0.1:8081 PAGES_DIR=/path/to/demo-data/user/pages \
 *   EDITOR_EMAIL=… EDITOR_PASSWORD=… node tests/e2e/live.mjs
 *
 * GRAV_LANGUAGES lists the target languages as grav:supertext pairs (default: the demo's
 * de:de-CH,fr:fr-CH); GRAV_SOURCE is the source language (default en).
 */
import { chromium } from 'playwright';
import { parse as parseYaml } from 'yaml';
import { mkdirSync, readdirSync, readFileSync, statSync, existsSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const B = (process.env.GRAV_URL ?? 'http://127.0.0.1:8081').replace(/\/$/, '');
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`); })();
const PAGES = need('PAGES_DIR');
const SOURCE = process.env.GRAV_SOURCE ?? 'en';
const TARGETS = (process.env.GRAV_LANGUAGES ?? 'de:de-CH,fr:fr-CH').split(',').map((p) => p.trim()).filter(Boolean)
	.map((p) => { const [grav, supertext] = p.split(':'); return { grav, supertext }; });
const HEADER_FIELDS = ['title', 'menu', 'metadata.description'];
const PANEL = 'grav-supertext-translation--panel';
const artifacts = fileURLToPath(new URL('./artifacts/', import.meta.url));
mkdirSync(artifacts, { recursive: true });

const failures = [];
function check(name, ok, detail = '') {
	console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${detail ? ` — ${detail}` : ''}`);
	if (!ok) failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
	return ok;
}
const note = (text) => console.log(`note ${text}`);

// Common words, to tell the target languages apart. Lower case, whole words.
const STOPWORDS = {
	en: ['the', 'and', 'is', 'are', 'with', 'for', 'you', 'your', 'our', 'of', 'to', 'we', 'who', 'what'],
	de: ['der', 'die', 'das', 'und', 'ist', 'sind', 'mit', 'für', 'sie', 'ihre', 'ihr', 'unser', 'unsere', 'wir', 'eine', 'ein', 'den', 'zu', 'von', 'was'],
	fr: ['le', 'les', 'et', 'est', 'sont', 'avec', 'pour', 'vous', 'votre', 'vos', 'notre', 'nos', 'nous', 'des', 'une', 'un', 'du', 'de', 'qui', 'que'],
	it: ['il', 'lo', 'gli', 'e', 'è', 'sono', 'con', 'per', 'lei', 'sua', 'suo', 'nostro', 'nostra', 'noi', 'una', 'un', 'del', 'della', 'di', 'che', 'chi'],
};
const words = (text) => text.toLowerCase().match(/[\p{L}]+/gu) ?? [];
const score = (text, lang) => words(text).filter((w) => STOPWORDS[lang].includes(w)).length;
const count = (haystack, re) => (String(haystack ?? '').match(re) ?? []).length;

// What the plugin sends to Supertext and must never end up in a page file.
const LEFTOVERS = ['data-st-id', 'data-st-ph', 'st-ph-', 'data-st-'];

/** A Grav page file: YAML front matter between "---" lines, then Markdown (as classes/PageFile.php). */
function readPage(path) {
	const content = readFileSync(path, 'utf8').replace(/\r\n?/g, '\n').replace(/^﻿/, '');
	const m = content.match(/^---\n([\s\S]*?)\n?---[ \t]*(?:\n|$)([\s\S]*)$/);
	return m ? { header: parseYaml(m[1]) ?? {}, body: m[2].replace(/^\n+/, ''), raw: content } : { header: {}, body: content, raw: content };
}
const field = (header, path) => path.split('.').reduce((v, k) => (v && typeof v === 'object' ? v[k] : undefined), header);

/** Markdown without syntax and inline code: the text a reader sees. */
const plain = (md) => String(md ?? '')
	.replace(/`[^`]*`/g, ' ')
	.replace(/\]\([^)]*\)/g, ']')
	.replace(/^>\s*\[![A-Z]+\]\s*$/gm, ' ')
	.replace(/[*_#>|[\]-]/g, ' ');
/** Body lines that carry text (not table separators, alert markers or blank lines). */
const textLines = (md) => String(md ?? '').split('\n').filter((l) => /\p{L}/u.test(plain(l)));

/** Markdown structure that a translation must keep, counted. */
function structure(md) {
	const s = String(md ?? '');
	return {
		headings: count(s, /^#{1,6} /gm),
		'list items': count(s, /^\s*[-*+] /gm),
		'table rows': count(s, /^\|.*\|\s*$/gm),
		'table cells': count(s, /\|/g),
		'quote lines': count(s, /^>/gm),
		alerts: count(s, /^>\s*\[![A-Z]+\]/gm),
		bold: count(s, /\*\*/g),
		italics: count(s.replace(/\*\*/g, ''), /(?<![\s\n])\*|\*(?![\s\n])/g),
		links: count(s, /\]\([^)]*\)/g),
		'inline code': count(s, /`[^`]+`/g),
		'HTML tags': count(s, /<\/?[a-z][^>]*>/gi),
	};
}
const urls = (md) => [...String(md ?? '').matchAll(/\]\(([^)]*)\)/g)].map((m) => m[1]).sort();
const codes = (md) => [...String(md ?? '').matchAll(/`[^`]+`/g)].map((m) => m[0]).sort();

/** Page folders with a source file: [{ dir, template, route }]. */
function sourcePages(dir = PAGES) {
	const out = [];
	for (const name of readdirSync(dir)) {
		const path = join(dir, name);
		if (!statSync(path).isDirectory()) continue;
		for (const file of readdirSync(path)) {
			const m = file.match(new RegExp(`^(.+)\\.${SOURCE}\\.md$`));
			if (m) {
				const route = relative(PAGES, path).split('/').map((part) => part.replace(/^\d+\./, '')).join('/');
				out.push({ dir: path, template: m[1], route });
			}
		}
		out.push(...sourcePages(path));
	}
	return out;
}

/** The page files of every source folder, with the translations' Supertext stamps. */
function snapshot(pages) {
	return Object.fromEntries(pages.map((p) => [p.route, {
		files: readdirSync(p.dir).filter((f) => f.endsWith('.md')).sort().join(','),
		stamps: Object.fromEntries(TARGETS.map((t) => {
			const file = join(p.dir, `${p.template}.${t.grav}.md`);
			return [t.grav, existsSync(file) ? field(readPage(file).header, 'supertext.translated') ?? null : null];
		})),
	}]));
}

// --- Setup -------------------------------------------------------------------------------
const pages = sourcePages();
console.log(`Source (${SOURCE}): ${pages.length} pages (${pages.map((p) => p.route).join(', ')}); targets: ${TARGETS.map((t) => `${t.grav} → ${t.supertext}`).join(', ')}`);
check('demo has sample pages', pages.length > 0);
check('demo has target languages', TARGETS.length > 0);
check('fresh demo (nothing translated yet)', pages.every((p) => TARGETS.every((t) => !existsSync(join(p.dir, `${p.template}.${t.grav}.md`)))));

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: 'en-US' });
page.setDefaultTimeout(30_000);

/** Opens the page in Admin2, translates it into every target language with the panel. */
async function translatePage(p, label, expected) {
	const tag = `${label} ${p.route}`;
	await page.goto(`${B}/admin/pages/edit/${p.route}`);
	const button = page.getByRole('button', { name: 'Supertext translation' });
	await button.waitFor();
	await page.waitForTimeout(1000);
	await button.click();
	const panel = page.locator(PANEL);
	await panel.locator('input[data-code]').first().waitFor();
	const errorsBefore = await panel.locator('.st-error').allTextContents();
	check(`${tag}: panel without errors`, errorsBefore.length === 0, errorsBefore.join(' | '));
	const warnings = await panel.locator('.st-box.st-warn').allTextContents();
	check(`${tag}: panel has no API key or language warning`, warnings.length === 0, warnings.map((w) => w.replace(/\s+/g, ' ').trim()).join(' | '));

	for (const t of TARGETS) {
		const box = panel.locator(`input[data-code="${t.grav}"]`);
		const usable = (await box.count()) === 1 && !(await box.isDisabled());
		check(`${tag}: ${t.grav} is mapped to ${t.supertext}`, usable && (await panel.locator(`li:has(input[data-code="${t.grav}"])`).innerText()).includes(t.supertext));
		// The panel re-renders on every change: click, then wait for the checked box.
		if (usable && !(await box.isChecked())) {
			await box.click();
			await panel.locator(`input[data-code="${t.grav}"]:checked`).waitFor();
		}
	}
	const started = Date.now();
	await panel.locator('button[data-action=translate]').click();
	const done = panel.locator('.st-results, .st-error, .st-confirm').first();
	await done.waitFor({ timeout: 900_000 });
	if (await panel.locator('.st-confirm').count()) {
		note(`${tag}: the panel asked to confirm replacing a translation; replacing it`);
		await panel.locator('button[data-action=overwrite]').click();
		await panel.locator('.st-results, .st-error').first().waitFor({ timeout: 900_000 });
	}
	await page.screenshot({ path: `${artifacts}${label}-${p.route.replace(/\//g, '_')}.png`, fullPage: true });
	console.log(`${tag}: ${Math.round((Date.now() - started) / 1000)} s`);

	const errors = await panel.locator('.st-error').allTextContents();
	check(`${tag}: no error shown in the panel`, errors.length === 0, errors.map((e) => e.replace(/\s+/g, ' ').trim()).join(' | '));
	const results = await panel.locator('.st-results li').evaluateAll((lis) => lis.map((li) => ({ ok: li.classList.contains('st-ok'), text: li.textContent.replace(/\s+/g, ' ').trim() })));
	check(`${tag}: a result for every language`, results.length === TARGETS.length, results.map((r) => r.text).join(' | '));
	for (const r of results) {
		// A message after "—" means text blocks came back untranslated or something was kept.
		check(`${tag}: ${r.text}`, r.ok && r.text.includes(expected) && !r.text.includes(' — '));
	}
}

// --- Log in as the editor ----------------------------------------------------------------
// The demo's username is the part of DEMO_EDITOR_EMAIL before the @ (demo/seed-accounts.php).
const username = process.env.EDITOR_USERNAME
	|| need('EDITOR_EMAIL').split('@')[0].toLowerCase().replace(/[^a-z0-9_-]+/g, '-').slice(0, 14).replace(/^-+|-+$/g, '');
await page.goto(`${B}/admin`);
await page.locator('#username').waitFor();
await page.locator('#username').fill(username);
await page.locator('#password').fill(need('EDITOR_PASSWORD'));
await page.keyboard.press('Enter');
const loggedIn = await page.waitForURL((url) => !url.pathname.includes('login'), { timeout: 30_000 }).then(() => true, () => false);
if (!check('editor can log in (DEMO_EDITOR_*)', loggedIn)) {
	await page.screenshot({ path: `${artifacts}login.png` });
	await browser.close();
	throw new Error(failures.join('\n'));
}
await page.waitForTimeout(1500);

// --- First translation -------------------------------------------------------------------
for (const p of pages) await translatePage(p, 'first-run', 'translation created');
const first = snapshot(pages);

for (const t of TARGETS) {
	const lang = t.grav.split(/[-_]/)[0].toLowerCase();
	const tag = `${t.grav} (${t.supertext})`;
	let fields = 0;
	let changed = 0;
	let text = '';
	for (const p of pages) {
		const file = join(p.dir, `${p.template}.${t.grav}.md`);
		if (!check(`${tag} ${p.route}: translation file ${p.template}.${t.grav}.md written`, existsSync(file))) continue;
		const source = readPage(join(p.dir, `${p.template}.${SOURCE}.md`));
		const target = readPage(file);
		check(`${tag} ${p.route}: unpublished (the plugin leaves new translations unpublished)`, target.header.published === false, `published: ${JSON.stringify(target.header.published)}`);
		check(`${tag} ${p.route}: Supertext stamp for ${t.supertext}`, field(target.header, 'supertext.language') === t.supertext, JSON.stringify(target.header.supertext));
		const left = LEFTOVERS.filter((l) => target.raw.includes(l));
		check(`${tag} ${p.route}: no protocol leftovers`, left.length === 0, left.join(', '));

		// Front matter fields: filled; a single word may stay the same ("Home" in German).
		for (const f of HEADER_FIELDS) {
			const s = field(source.header, f);
			if (typeof s !== 'string' || !s.trim()) continue;
			const v = field(target.header, f);
			fields++;
			if (!check(`${tag} ${p.route}: ${f} filled`, typeof v === 'string' && v.trim() !== '', JSON.stringify(v))) continue;
			if (v !== s) changed++;
			else note(`${tag} ${p.route}: ${f} "${s}" unchanged`);
			text += ` ${v}`;
		}

		// Body: the same Markdown structure, every text line filled.
		const sourceLines = textLines(source.body);
		const targetLines = textLines(target.body);
		check(`${tag} ${p.route}: body has every text line`, targetLines.length === sourceLines.length, `${targetLines.length}/${sourceLines.length}`);
		sourceLines.forEach((s, i) => {
			fields++;
			const v = targetLines[i] ?? '';
			if (v.trim() !== '' && v !== s) changed++;
			else if (v === s) note(`${tag} ${p.route}: line "${s.slice(0, 60)}" unchanged`);
		});
		text += ` ${plain(target.body)}`;
		const want = structure(source.body);
		const got = structure(target.body);
		for (const [what, n] of Object.entries(want)) {
			if (n || got[what]) check(`${tag} ${p.route}: keeps its ${what}`, got[what] === n, `${got[what]}/${n}`);
		}
		check(`${tag} ${p.route}: link targets unchanged`, JSON.stringify(urls(target.body)) === JSON.stringify(urls(source.body)), urls(target.body).join(' '));
		check(`${tag} ${p.route}: inline code untouched`, JSON.stringify(codes(target.body)) === JSON.stringify(codes(source.body)), codes(target.body).join(' '));
	}
	check(`${tag}: texts were changed by the translation`, fields > 0 && changed / fields >= 0.8, `${changed}/${fields} fields`);

	if (STOPWORDS[lang]) {
		const scores = Object.fromEntries(Object.keys(STOPWORDS).map((l) => [l, score(text, l)]));
		const best = Object.entries(scores).sort((a, b) => b[1] - a[1])[0][0];
		check(`${tag}: text is in the target language`, best === lang, JSON.stringify(scores));
	}
	if (/^de-ch$/i.test(t.supertext)) check(`${tag}: Swiss German spelling (no "ß")`, !text.includes('ß'));
}

// --- Translating again updates in place ---------------------------------------------------
// One second at least between the runs, so the stamp's time (seconds) can change.
await page.waitForTimeout(1500);
for (const p of pages) await translatePage(p, 'second-run', 'translation updated');
const second = snapshot(pages);
for (const p of pages) {
	check(`${p.route}: translating again writes the same files (no duplicates)`, first[p.route].files === second[p.route].files, `${first[p.route].files} → ${second[p.route].files}`);
	for (const t of TARGETS) {
		check(`${p.route} ${t.grav}: translating again updated the translation`, second[p.route].stamps[t.grav] !== null && second[p.route].stamps[t.grav] !== first[p.route].stamps[t.grav],
			`${first[p.route].stamps[t.grav]} → ${second[p.route].stamps[t.grav]}`);
		const header = readPage(join(p.dir, `${p.template}.${t.grav}.md`)).header;
		check(`${p.route} ${t.grav}: still unpublished after translating again`, header.published === false);
	}
}

await browser.close();

if (failures.length) {
	console.error(`\n${failures.length} check(s) failed:\n- ${failures.join('\n- ')}`);
	process.exit(1);
}
console.log('\nAll end-to-end checks passed.');
