/**
 * `supertext-languages` field — the setting `language_codes`: which Supertext language each
 * language of the site is. One row per site language with a select of the languages Supertext
 * offers (name and code), pre-selected with the Supertext code the Grav code is exactly equal to
 * (es, nl, de-CH). Bare codes such as de or fr have no such match: they show "Not mapped" until
 * the administrator chooses a variant.
 *
 * Value: a map { grav code: Supertext code } of the rows the administrator chose; rows left on
 * their exact match aren't stored (the plugin finds them on its own). Entries for languages the
 * site no longer has are kept.
 *
 * Admin2 loads this file through GET /api/v1/gpm/plugins/supertext-translation/fields and
 * defines it under the tag in window.__GRAV_FIELD_TAG. Data: GET /api/v1/supertext/settings
 * (classes/Controller/SettingsController.php).
 */
const TAG = window.__GRAV_FIELD_TAG || 'grav-supertext-translation--supertext-languages';

// Strings: ICU.PLUGIN_SUPERTEXT_TRANSLATION.FIELDS.* in languages.yaml (en, de, fr, it), read through
// Admin2's window.__GRAV_I18N. EN is the fallback and must match languages.yaml (tests/LanguagesTest.php).
const KEY_PREFIX = 'PLUGIN_SUPERTEXT_TRANSLATION.FIELDS.';
const EN = {
    LOAD_ERROR: 'The Supertext status could not be loaded ({error}). Enable the plugin, save the settings and reload this page.',
    LANG_SOURCE: 'source language, sent as {code}',
    LANG_CHOOSE: 'Choose a Supertext language',
    LANG_NOT_MAPPED: 'Not mapped: choose a Supertext language, or this language can’t be translated.',
    LANG_NO_POLITENESS: 'Supertext offers no formal or informal form for this language: Supertext’s default is used.',
    LANG_OTHER: '{code} (not in the list)',
    LANG_UNAVAILABLE: 'Enable the plugin and reload this page to choose the languages.',
};

function t(key, params) {
    const i18n = window.__GRAV_I18N;
    if (i18n && typeof i18n.has === 'function' && i18n.has(KEY_PREFIX + key)) {
        return i18n.t(KEY_PREFIX + key, params);
    }
    return String(EN[key] ?? key).replace(/\{(\w+)\}/g, (match, name) => (params && name in params ? String(params[name]) : match));
}

function esc(text) {
    const div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML.replace(/"/g, '&quot;');
}

/** Codes compare without case and with "_" = "-", like the Supertext SDK does. */
const key = (code) => String(code ?? '').trim().toLowerCase().replace(/_/g, '-');

/** The stored value as a plain map; accepts the older list of {grav, supertext} rows. */
function toMap(value) {
    const map = {};
    if (Array.isArray(value)) {
        value.forEach((row) => { if (row && row.grav && row.supertext) map[String(row.grav)] = String(row.supertext); });
    } else if (value && typeof value === 'object') {
        Object.entries(value).forEach(([k, v]) => { if (typeof v === 'string' && v.trim() !== '') map[k] = v.trim(); });
    }
    return map;
}

class SupertextLanguagesField extends HTMLElement {
    constructor() {
        super();
        this._field = null;
        this._map = {};
        this._data = null;
        this._error = '';
    }

    set field(v) { this._field = v; }
    get field() { return this._field; }

    set value(v) {
        const map = toMap(v);
        if (JSON.stringify(map) === JSON.stringify(this._map)) return;
        this._map = map;
        if (this.isConnected) this._render();
    }
    get value() { return this._map; }

    connectedCallback() {
        const i18n = window.__GRAV_I18N;
        if (i18n && typeof i18n.subscribe === 'function' && !this._unsubscribe) {
            const unsubscribe = i18n.subscribe(() => this._render());
            this._unsubscribe = typeof unsubscribe === 'function' ? unsubscribe : null;
        }
        this._render();
        this._load();
    }

    disconnectedCallback() {
        if (this._unsubscribe) this._unsubscribe();
        this._unsubscribe = null;
    }

    async _load() {
        const headers = { Accept: 'application/json' };
        if (window.__GRAV_API_TOKEN) headers['X-API-Token'] = window.__GRAV_API_TOKEN;
        if (window.__GRAV_CONFIG__ && window.__GRAV_CONFIG__.environment) headers['X-Grav-Environment'] = window.__GRAV_CONFIG__.environment;
        try {
            const url = (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1') + '/supertext/settings';
            const response = await fetch(url, { headers, credentials: 'same-origin' });
            const json = await response.json().catch(() => null);
            if (!response.ok) throw new Error((json && (json.detail || json.message || json.title)) || 'HTTP ' + response.status);
            this._data = json && json.data !== undefined ? json.data : json;
            this._error = '';
        } catch (e) {
            this._error = e.message;
        }
        this._render();
    }

    /** The administrator's choice for a Grav code, if any. */
    _mapped(code) {
        const found = Object.keys(this._map).find((k) => key(k) === key(code));
        return found !== undefined ? this._map[found] : null;
    }

    _set(code, supertext) {
        const map = {};
        Object.entries(this._map).forEach(([k, v]) => { if (key(k) !== key(code)) map[k] = v; });
        if (supertext) map[code] = supertext;
        this._map = map;
        this.dispatchEvent(new CustomEvent('change', { detail: { ...map }, bubbles: true }));
        this._render();
    }

    _target(code) {
        return (this._data.targets || []).find((l) => key(l.code) === key(code)) || null;
    }

    _render() {
        let body = '';
        const d = this._data;
        if (!d) {
            if (this._error) {
                const entries = Object.entries(this._map).map(([k, v]) => `${k} → ${v}`).join(', ');
                body += `<div class="stl-box stl-warn" role="alert">${esc(t('LOAD_ERROR', { error: this._error }))}<br>${esc(t('LANG_UNAVAILABLE'))}${entries ? `<br><code>${esc(entries)}</code>` : ''}</div>`;
            }
        } else {
            body += '<ul class="stl-rows">';
            for (const lang of d.languages || []) {
                const chosen = this._mapped(lang.code);
                const selected = chosen || lang.exact || '';
                const known = selected ? this._target(selected) : null;
                let options = `<option value="" ${selected ? '' : 'selected'}>${esc(t('LANG_CHOOSE'))}</option>`;
                if (selected && !known) {
                    options += `<option value="${esc(selected)}" selected>${esc(t('LANG_OTHER', { code: selected }))}</option>`;
                }
                for (const target of d.targets || []) {
                    const isSelected = known && known.code === target.code;
                    options += `<option value="${esc(target.code)}" ${isSelected ? 'selected' : ''}>${esc(target.name)} (${esc(target.code)})</option>`;
                }
                let notes = '';
                // Sources are bare languages: one mapping serves both directions (de-CH → de), and a
                // bare Grav code such as en is a source language on its own.
                const sourceCode = !lang.is_source ? null : selected ? key(selected).split('-')[0] : lang.source_code;
                if (sourceCode) {
                    notes += `<div class="stl-note">${esc(t('LANG_SOURCE', { code: sourceCode }))}</div>`;
                } else if (!selected) {
                    notes += `<div class="stl-note stl-warn" data-testid="supertext-not-mapped">${esc(t('LANG_NOT_MAPPED'))}</div>`;
                } else if (d.politeness && d.politeness !== 'default' && known && known.politeness === false) {
                    notes += `<div class="stl-note stl-info">${esc(t('LANG_NO_POLITENESS'))}</div>`;
                }
                body += `<li class="stl-row">
                    <label class="stl-lang" for="stl-${esc(lang.code)}">${esc(lang.name)} <span class="stl-code">${esc(lang.code)}</span></label>
                    <select id="stl-${esc(lang.code)}" data-code="${esc(lang.code)}" class="${selected || sourceCode ? '' : 'stl-missing'}">${options}</select>
                    ${notes}
                </li>`;
            }
            body += '</ul>';
        }

        this.innerHTML = `
            <style>
                ${TAG} { display: block; font-size: 13px; color: var(--foreground, #111827); }
                ${TAG} .stl-rows { list-style: none; margin: 0; padding: 0; }
                ${TAG} .stl-row { display: grid; grid-template-columns: minmax(120px, 200px) minmax(0, 340px); gap: 4px 12px; align-items: center; padding: 6px 0; border-bottom: 1px solid var(--border, #e5e7eb); }
                ${TAG} .stl-row:last-child { border-bottom: 0; }
                ${TAG} .stl-lang { font-weight: 500; }
                ${TAG} .stl-code { font-weight: 400; color: var(--muted-foreground, #6b7280); margin-left: 4px; }
                ${TAG} select { font: inherit; padding: 6px 8px; border: 1px solid var(--border, #d1d5db); border-radius: 6px; background: var(--background, #fff); color: var(--foreground, #111827); min-width: 0; }
                ${TAG} select.stl-missing { border-color: #b45309; }
                ${TAG} .stl-note { grid-column: 2; font-size: 12px; color: var(--muted-foreground, #6b7280); }
                ${TAG} .stl-warn { color: #b45309; }
                ${TAG} .stl-info { color: #1d4ed8; }
                ${TAG} .stl-box { border-radius: 8px; padding: 10px 12px; border: 1px solid currentColor; background: color-mix(in srgb, currentColor 7%, transparent); }
            </style>
            ${body}`;

        this.querySelectorAll('select[data-code]').forEach((select) => {
            select.addEventListener('change', () => this._set(select.dataset.code, select.value));
        });
    }
}

if (!customElements.get(TAG)) {
    customElements.define(TAG, SupertextLanguagesField);
}
