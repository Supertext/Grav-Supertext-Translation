/**
 * Supertext translation panel for the Admin2 page editor.
 *
 * Admin2 loads this file through GET /api/v1/gpm/plugins/supertext-translation/panel-script
 * when an editor clicks the Supertext button in the page editor toolbar. It defines the
 * custom element named in window.__GRAV_PANEL_TAG and sets its `route`, `lang` and `type`
 * attributes for the page being edited. The element fires `close` to close the panel.
 */
const TAG = window.__GRAV_PANEL_TAG || 'grav-supertext-translation--panel';

// Strings: ICU.PLUGIN_SUPERTEXT_TRANSLATION.PANEL.* in languages.yaml (en, de, fr, it), read
// through Admin2's window.__GRAV_I18N in the user's admin language. EN is the fallback for an
// Admin2 without it and must match the English strings in languages.yaml (tests/LanguagesTest.php).
const KEY_PREFIX = 'PLUGIN_SUPERTEXT_TRANSLATION.PANEL.';
const EN = {
    TITLE: 'Supertext translation',
    CLOSE: 'Close',
    LOADING: 'Loading…',
    HTTP_ERROR: 'The server answered with HTTP {status}.',
    STATE_MISSING: 'Not translated yet',
    STATE_CURRENT: 'Up to date',
    STATE_OUTDATED: 'Source changed since',
    STATE_EDITED: 'Edited after translation',
    STATE_MANUAL: 'Exists, not from Supertext',
    FIELD_TITLE: 'title',
    FIELD_MENU: 'menu label',
    FIELD_META_DESCRIPTION: 'meta description',
    FIELD_META_KEYWORDS: 'meta keywords',
    FIELD_SUMMARY: 'summary',
    RESULT_LINE: '{name}: {result}',
    RESULT_CREATED: 'translation created',
    RESULT_UPDATED: 'translation updated',
    RESULT_SKIPPED: 'kept as it is',
    RESULT_ERROR: 'not translated',
    INTRO: 'Translate {title} from {source} into:',
    NO_API_KEY: 'No Supertext API key is configured yet. An administrator can add it under Plugins → Supertext Translation. No Supertext account yet? {signup}. Generate the API key at {apikey} (requires the Admin role).',
    SIGNUP_LINK: 'Create one at supertext.com',
    NO_LANGUAGES: 'This site has no other languages. Add languages in the system configuration first.',
    CONFIRM_TITLE_ONE: 'Replace existing translation?',
    CONFIRM_TITLE_MANY: 'Replace existing translations?',
    CONFIRM_EDITED: '{name} was edited after it was translated.',
    CONFIRM_MANUAL: '{name} already exists and was not made with Supertext.',
    CONFIRM_TEXT: 'Translating again replaces that text with a new translation of the {source} page.',
    REPLACE: 'Replace',
    CANCEL: 'Cancel',
    TRANSLATE: 'Translate',
    TRANSLATE_ONE: 'Translate into 1 language',
    TRANSLATE_MANY: 'Translate into {count} languages',
    TRANSLATING: 'Translating…',
    BUSY_HINT: 'Supertext is translating the page. This usually takes 10 to 60 seconds; you can keep editing in the meantime.',
    REVIEW_HINT: 'New translations are saved unpublished unless your administrator changed that. Switch the editor to the language to review and publish them.',
    FOOT: 'Supertext translates the page content.',
    FOOT_FIELDS: 'Supertext translates the page content and these fields: {fields}.',
    FOOT_KEPT: 'Translations edited by hand are never replaced without asking.',
};

function t(key, params) {
    const i18n = window.__GRAV_I18N;
    if (i18n && typeof i18n.has === 'function' && i18n.has(KEY_PREFIX + key)) {
        return i18n.t(KEY_PREFIX + key, params);
    }
    return String(EN[key] ?? key).replace(/\{(\w+)\}/g, (match, name) => (params && name in params ? String(params[name]) : match));
}

const STATE_LABELS = {
    missing: { key: 'STATE_MISSING', tone: 'muted' },
    current: { key: 'STATE_CURRENT', tone: 'ok' },
    outdated: { key: 'STATE_OUTDATED', tone: 'info' },
    edited: { key: 'STATE_EDITED', tone: 'warn' },
    manual: { key: 'STATE_MANUAL', tone: 'warn' },
};

const FIELD_LABELS = {
    title: 'FIELD_TITLE',
    menu: 'FIELD_MENU',
    'metadata.description': 'FIELD_META_DESCRIPTION',
    'metadata.keywords': 'FIELD_META_KEYWORDS',
    summary: 'FIELD_SUMMARY',
};

const RESULT_TEXT = {
    created: 'RESULT_CREATED',
    updated: 'RESULT_UPDATED',
    skipped: 'RESULT_SKIPPED',
    error: 'RESULT_ERROR',
};

class SupertextTranslationPanel extends HTMLElement {
    static get observedAttributes() { return ['route']; }

    constructor() {
        super();
        this._status = null;
        this._selected = new Set();
        this._busy = false;
        this._error = '';
        this._results = null;
        this._confirm = null;
        this._loadedRoute = null;
    }

    connectedCallback() {
        const i18n = window.__GRAV_I18N;
        if (i18n && typeof i18n.subscribe === 'function' && !this._unsubscribe) {
            // Re-render when the user switches the admin language.
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

    attributeChangedCallback(name, oldValue, newValue) {
        if (name === 'route' && oldValue !== newValue && this.isConnected) {
            this._results = null;
            this._load();
        }
    }

    // ─── API ───────────────────────────────────────────────────────────────
    _url(path) {
        return (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1') + path;
    }

    _headers(json) {
        const h = { Accept: 'application/json' };
        if (json) h['Content-Type'] = 'application/json';
        if (window.__GRAV_API_TOKEN) h['X-API-Token'] = window.__GRAV_API_TOKEN;
        if (window.__GRAV_CONFIG__ && window.__GRAV_CONFIG__.environment) h['X-Grav-Environment'] = window.__GRAV_CONFIG__.environment;
        return h;
    }

    async _call(method, path, body) {
        const response = await fetch(this._url(path), {
            method,
            headers: this._headers(body !== undefined),
            body: body !== undefined ? JSON.stringify(body) : undefined,
            credentials: 'same-origin',
        });
        let json = null;
        try { json = await response.json(); } catch (e) { /* not JSON */ }
        if (!response.ok) {
            const detail = json && (json.detail || json.message || json.title);
            throw new Error(detail || t('HTTP_ERROR', { status: response.status }));
        }
        return json && json.data !== undefined ? json.data : json;
    }

    async _load() {
        const route = this.getAttribute('route');
        if (!route) return;
        this._loadedRoute = route;
        this._error = '';
        this._status = null;
        this._render();
        try {
            const status = await this._call('GET', '/supertext/status?route=' + encodeURIComponent(route));
            if (this._loadedRoute !== route) return;
            this._status = status;
            this._selected = new Set(
                status.languages.filter((l) => l.state === 'missing' || l.state === 'outdated').map((l) => l.code),
            );
        } catch (e) {
            this._error = e.message;
        }
        this._render();
    }

    async _translate(overwrite) {
        const languages = [...this._selected];
        if (!languages.length) return;
        const needsConfirm = this._status.languages.filter(
            (l) => languages.includes(l.code) && (l.state === 'edited' || l.state === 'manual'),
        );
        if (!overwrite && needsConfirm.length) {
            this._confirm = needsConfirm;
            this._render();
            return;
        }
        this._confirm = null;
        this._busy = true;
        this._error = '';
        this._results = null;
        this._render();
        try {
            const data = await this._call('POST', '/supertext/translate', {
                route: this.getAttribute('route'),
                languages,
                overwrite: !!overwrite,
            });
            this._results = data.results;
            const counts = { count: data.results.filter((r) => r.result === 'error' || r.result === 'skipped').length };
            this.dispatchEvent(new CustomEvent('badge', { detail: counts }));
        } catch (e) {
            this._error = e.message;
        }
        this._busy = false;
        await this._load();
    }

    // ─── Rendering ─────────────────────────────────────────────────────────
    _esc(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    _date(iso) {
        if (!iso) return '';
        const d = new Date(iso);
        const locale = (window.__GRAV_I18N && window.__GRAV_I18N.locale) || undefined;
        try {
            return isNaN(d) ? '' : d.toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' });
        } catch (e) {
            return d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
        }
    }

    /** A translated string as HTML: `params` are text, `html` are inserted as markup. */
    _html(key, params = {}, html = {}) {
        const all = { ...params };
        const names = Object.keys(html);
        names.forEach((name, i) => { all[name] = `\u0001${i}\u0001`; });
        let out = this._esc(t(key, all));
        names.forEach((name, i) => { out = out.split(`\u0001${i}\u0001`).join(html[name]); });
        return out;
    }

    _render() {
        const s = this._status;
        let body = '';

        if (this._error) {
            body += `<div class="st-box st-error" role="alert">${this._esc(this._error)}</div>`;
        }

        if (!s && !this._error) {
            body += `<p class="st-muted">${this._esc(t('LOADING'))}</p>`;
        }

        if (s) {
            body += `<p class="st-intro">${this._html('INTRO', {}, {
                title: `<strong>${this._esc(s.title)}</strong>`,
                source: `<strong>${this._esc(s.source.name)}</strong>`,
            })}</p>`;

            if (!s.api_key_configured) {
                body += `<div class="st-box st-warn">${this._html('NO_API_KEY', {}, {
                    signup: `<a href="https://www.supertext.com/person/en/account/signin" target="_blank" rel="noopener noreferrer">${this._esc(t('SIGNUP_LINK'))}</a>`,
                    apikey: '<a href="https://www.supertext.com/en/integrations/api" target="_blank" rel="noopener noreferrer">supertext.com → Integrations → API</a>',
                })}</div>`;
            }
            if (!s.languages.length) {
                body += `<div class="st-box st-info">${this._esc(t('NO_LANGUAGES'))}</div>`;
            }

            body += '<ul class="st-langs">';
            for (const l of s.languages) {
                const state = STATE_LABELS[l.state];
                const label = state ? { text: t(state.key), tone: state.tone } : { text: l.state, tone: 'muted' };
                const when = l.translated && l.state !== 'manual' ? ` · ${this._esc(this._date(l.translated))}` : '';
                body += `<li>
                    <label class="st-lang">
                        <input type="checkbox" data-code="${this._esc(l.code)}" ${this._selected.has(l.code) ? 'checked' : ''} ${this._busy ? 'disabled' : ''}>
                        <span class="st-name">${this._esc(l.name)} <span class="st-code">${this._esc(l.supertext_code)}</span></span>
                        <span class="st-state st-${label.tone}">${this._esc(label.text)}${when}</span>
                    </label>
                </li>`;
            }
            body += '</ul>';

            if (this._confirm) {
                const lines = this._confirm.map((l) => `<li>${this._html(
                    l.state === 'edited' ? 'CONFIRM_EDITED' : 'CONFIRM_MANUAL', {}, { name: `<strong>${this._esc(l.name)}</strong>` },
                )}</li>`).join('');
                body += `<div class="st-box st-warn" role="alert">
                    <strong>${this._esc(t(this._confirm.length > 1 ? 'CONFIRM_TITLE_MANY' : 'CONFIRM_TITLE_ONE'))}</strong>
                    <ul class="st-confirm">${lines}</ul>
                    <p>${this._esc(t('CONFIRM_TEXT', { source: s.source.name }))}</p>
                    <div class="st-actions">
                        <button type="button" class="st-btn st-danger" data-action="overwrite">${this._esc(t('REPLACE'))}</button>
                        <button type="button" class="st-btn" data-action="cancel">${this._esc(t('CANCEL'))}</button>
                    </div>
                </div>`;
            } else {
                const n = this._selected.size;
                const label = n === 0 ? t('TRANSLATE') : n === 1 ? t('TRANSLATE_ONE') : t('TRANSLATE_MANY', { count: n });
                body += `<div class="st-actions">
                    <button type="button" class="st-btn st-primary" data-action="translate" ${n && !this._busy && s.api_key_configured ? '' : 'disabled'}>
                        ${this._busy ? `<span class="st-spinner" aria-hidden="true"></span> ${this._esc(t('TRANSLATING'))}` : this._esc(label)}
                    </button>
                </div>`;
                if (this._busy) {
                    body += `<p class="st-muted">${this._esc(t('BUSY_HINT'))}</p>`;
                }
            }

            if (this._results) {
                body += '<ul class="st-results">';
                for (const r of this._results) {
                    const tone = r.result === 'error' ? 'error' : r.result === 'skipped' ? 'warn' : 'ok';
                    const text = RESULT_TEXT[r.result] ? t(RESULT_TEXT[r.result]) : r.result;
                    body += `<li class="st-${tone}">${this._html('RESULT_LINE', { result: text }, { name: `<strong>${this._esc(r.name)}</strong>` })}${r.message ? ` — ${this._esc(r.message)}` : ''}</li>`;
                }
                body += '</ul>';
                if (this._results.some((r) => r.result === 'created')) {
                    body += `<p class="st-muted">${this._esc(t('REVIEW_HINT'))}</p>`;
                }
            }

            const fields = (s.translated_fields || []).map((f) => (FIELD_LABELS[f] ? t(FIELD_LABELS[f]) : f)).join(', ');
            body += `<p class="st-foot">${this._esc(fields ? t('FOOT_FIELDS', { fields }) : t('FOOT'))}
                ${this._esc(t('FOOT_KEPT'))}</p>`;
        }

        this.innerHTML = `
            <style>
                ${TAG} { display: flex; flex-direction: column; height: 100%; font-size: 14px; color: var(--foreground, #111827); }
                ${TAG} .st-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid var(--border, #e5e7eb); }
                ${TAG} .st-head h2 { margin: 0; font-size: 16px; font-weight: 600; display: flex; gap: 8px; align-items: center; }
                ${TAG} .st-close { background: none; border: 0; font-size: 20px; line-height: 1; cursor: pointer; color: var(--muted-foreground, #6b7280); padding: 4px 8px; }
                ${TAG} .st-body { padding: 16px 18px; overflow-y: auto; }
                ${TAG} .st-intro { margin: 0 0 12px; }
                ${TAG} .st-langs, ${TAG} .st-results { list-style: none; margin: 0 0 14px; padding: 0; }
                ${TAG} .st-langs li { border: 1px solid var(--border, #e5e7eb); border-radius: 8px; margin-bottom: 8px; }
                ${TAG} .st-lang { display: grid; grid-template-columns: auto 1fr; gap: 2px 10px; padding: 10px 12px; cursor: pointer; align-items: center; }
                ${TAG} .st-lang input { grid-row: span 2; width: 16px; height: 16px; accent-color: var(--primary, #2563eb); }
                ${TAG} .st-name { font-weight: 500; }
                ${TAG} .st-code { font-weight: 400; font-size: 12px; color: var(--muted-foreground, #6b7280); margin-left: 4px; }
                ${TAG} .st-state { font-size: 12px; }
                ${TAG} .st-muted { color: var(--muted-foreground, #6b7280); font-size: 13px; }
                ${TAG} .st-ok { color: #15803d; }
                ${TAG} .st-info { color: #1d4ed8; }
                ${TAG} .st-warn { color: #b45309; }
                ${TAG} .st-error { color: var(--destructive, #dc2626); }
                ${TAG} .st-box { border-radius: 8px; padding: 10px 12px; margin: 0 0 14px; border: 1px solid currentColor; background: color-mix(in srgb, currentColor 7%, transparent); }
                ${TAG} .st-box p { margin: 6px 0 10px; color: var(--foreground, #111827); }
                ${TAG} .st-actions { display: flex; gap: 8px; margin-bottom: 12px; }
                ${TAG} .st-btn { border: 1px solid var(--border, #d1d5db); background: var(--background, #fff); color: var(--foreground, #111827); border-radius: 6px; padding: 8px 14px; font: inherit; font-weight: 500; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
                ${TAG} .st-btn[disabled] { opacity: .5; cursor: not-allowed; }
                ${TAG} .st-primary { background: var(--primary, #2563eb); border-color: var(--primary, #2563eb); color: var(--primary-foreground, #fff); }
                ${TAG} .st-danger { background: var(--destructive, #dc2626); border-color: var(--destructive, #dc2626); color: #fff; }
                ${TAG} .st-results li { margin-bottom: 6px; }
                ${TAG} .st-confirm { margin: 6px 0 0; padding-left: 20px; list-style: disc; color: var(--foreground, #111827); }
                ${TAG} .st-results li strong { color: var(--foreground, #111827); }
                ${TAG} .st-foot { margin-top: 18px; font-size: 12px; color: var(--muted-foreground, #6b7280); }
                ${TAG} .st-spinner { width: 14px; height: 14px; border: 2px solid currentColor; border-right-color: transparent; border-radius: 50%; animation: st-spin .8s linear infinite; }
                @keyframes st-spin { to { transform: rotate(360deg); } }
            </style>
            <div class="st-head">
                <h2>${this._esc(t('TITLE'))}</h2>
                <button type="button" class="st-close" data-action="close" aria-label="${this._esc(t('CLOSE'))}">×</button>
            </div>
            <div class="st-body">${body}</div>`;

        this.querySelectorAll('input[data-code]').forEach((input) => {
            input.addEventListener('change', () => {
                if (input.checked) this._selected.add(input.dataset.code);
                else this._selected.delete(input.dataset.code);
                this._confirm = null;
                this._render();
            });
        });
        this.querySelectorAll('[data-action]').forEach((button) => {
            button.addEventListener('click', () => {
                const action = button.dataset.action;
                if (action === 'close') this.dispatchEvent(new CustomEvent('close'));
                if (action === 'translate') this._translate(false);
                if (action === 'overwrite') this._translate(true);
                if (action === 'cancel') { this._confirm = null; this._render(); }
            });
        });
    }
}

if (!customElements.get(TAG)) {
    customElements.define(TAG, SupertextTranslationPanel);
}
