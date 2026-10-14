/**
 * `supertext-status` field — the plugin's settings page shows the latest automatic check of the
 * Supertext API key here (with its time), a "Check again" button, and a note when the
 * environment variable SUPERTEXT_VERSION_REPORTS overrides the version reports setting.
 *
 * Admin2 loads this file through GET /api/v1/gpm/plugins/supertext-translation/fields and
 * defines it under the tag in window.__GRAV_FIELD_TAG. The field has no value of its own
 * (blueprints.yaml: validate.ignore). Data: GET /api/v1/supertext/settings; "Check again":
 * POST /api/v1/supertext/check (classes/Controller/SettingsController.php).
 *
 * The key is checked on the server every time the settings are saved; this field reloads a
 * few seconds after a button is pressed on the page (e.g. Save) to show the new result.
 */
const TAG = window.__GRAV_FIELD_TAG || 'grav-supertext-translation--supertext-status';

// Strings: ICU.PLUGIN_SUPERTEXT_TRANSLATION.FIELDS.* in languages.yaml (en, de, fr, it), read through
// Admin2's window.__GRAV_I18N. EN is the fallback and must match languages.yaml (tests/LanguagesTest.php).
const KEY_PREFIX = 'PLUGIN_SUPERTEXT_TRANSLATION.FIELDS.';
const EN = {
    KEYCHECK_OK: 'API key checked: the connection to Supertext works.',
    KEYCHECK_INVALID: 'Supertext rejected the API key. Check that you copied the whole key, or generate a new one at supertext.com → Integrations → API (requires the Admin role). No Supertext account yet? Create one at supertext.com.',
    KEYCHECK_MISSING: 'No API key is set yet. No Supertext account yet? Create one at supertext.com. Generate your API key at supertext.com → Integrations → API (requires the Admin role).',
    KEYCHECK_UNREACHABLE: 'The API key couldn’t be checked because Supertext can’t be reached. The settings are saved; check again later.',
    KEYCHECK_ERROR: 'The API key couldn’t be checked: Supertext answered with an error ({status}). The settings are saved; check again later.',
    CHECKED_AT: 'Checked {time}',
    NOT_CHECKED: 'Not checked yet. The API key is checked every time you save these settings.',
    CHECK_AGAIN: 'Check again',
    CHECKING: 'Checking…',
    REPORTS_ENV_OFF: 'The environment variable SUPERTEXT_VERSION_REPORTS={value} turns version reports off, whatever the setting below says.',
    REPORTS_ENV_ON: 'The environment variable SUPERTEXT_VERSION_REPORTS={value} turns version reports on, whatever the setting below says.',
    LOAD_ERROR: 'The Supertext status could not be loaded ({error}). Enable the plugin, save the settings and reload this page.',
};

const SIGNUP_URL = 'https://www.supertext.com/person/en/account/signin';
const API_KEY_URL = 'https://www.supertext.com/en/integrations/api';

const TONES = { ok: 'ok', invalid: 'error', missing: 'warn', unreachable: 'warn', error: 'warn' };

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
    return div.innerHTML;
}

/** A key check message as HTML: "supertext.com → Integrations → API" and "supertext.com" become links (new tab). */
function withLinks(text) {
    const link = (url, label) => `<a href="${url}" target="_blank" rel="noopener">${label}</a>`;
    return esc(text)
        .split('supertext.com → Integrations → API').join('\u0001')
        .split('supertext.com').join(link(SIGNUP_URL, 'supertext.com'))
        .split('\u0001').join(link(API_KEY_URL, 'supertext.com → Integrations → API'));
}

class SupertextStatusField extends HTMLElement {
    constructor() {
        super();
        this._field = null;
        this._value = null;
        this._data = null;
        this._error = '';
        this._busy = false;
        this._timers = [];
        this._onPageAction = this._onPageAction.bind(this);
    }

    set field(v) { this._field = v; }
    get field() { return this._field; }
    set value(v) { this._value = v; }
    get value() { return this._value; }

    connectedCallback() {
        document.addEventListener('click', this._onPageAction, true);
        document.addEventListener('keydown', this._onPageAction, true);
        const i18n = window.__GRAV_I18N;
        if (i18n && typeof i18n.subscribe === 'function' && !this._unsubscribe) {
            const unsubscribe = i18n.subscribe(() => this._render());
            this._unsubscribe = typeof unsubscribe === 'function' ? unsubscribe : null;
        }
        this._render();
        this._load();
    }

    disconnectedCallback() {
        document.removeEventListener('click', this._onPageAction, true);
        document.removeEventListener('keydown', this._onPageAction, true);
        this._timers.forEach(clearTimeout);
        this._timers = [];
        if (this._unsubscribe) this._unsubscribe();
        this._unsubscribe = null;
    }

    /** After Save (a button elsewhere on the page, or Ctrl/Cmd+S), show the new check result. */
    _onPageAction(event) {
        if (event.type === 'keydown' && !((event.ctrlKey || event.metaKey) && String(event.key).toLowerCase() === 's')) return;
        if (event.type === 'click' && (!(event.target instanceof Element) || !event.target.closest('button') || this.contains(event.target))) return;
        this._timers.forEach(clearTimeout);
        this._timers = [2000, 6000, 15000].map((ms) => setTimeout(() => this._load(true), ms));
    }

    _url(path) {
        return (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1') + path;
    }

    async _call(method, path) {
        const headers = { Accept: 'application/json' };
        if (window.__GRAV_API_TOKEN) headers['X-API-Token'] = window.__GRAV_API_TOKEN;
        if (window.__GRAV_CONFIG__ && window.__GRAV_CONFIG__.environment) headers['X-Grav-Environment'] = window.__GRAV_CONFIG__.environment;
        const response = await fetch(this._url(path), { method, headers, credentials: 'same-origin' });
        let json = null;
        try { json = await response.json(); } catch (e) { /* not JSON */ }
        if (!response.ok) throw new Error((json && (json.detail || json.message || json.title)) || 'HTTP ' + response.status);
        return json && json.data !== undefined ? json.data : json;
    }

    async _load(quiet) {
        try {
            this._data = await this._call('GET', '/supertext/settings');
            this._error = '';
        } catch (e) {
            if (!quiet || !this._data) this._error = e.message;
        }
        this._render();
    }

    async _check() {
        this._busy = true;
        this._render();
        try {
            this._data = await this._call('POST', '/supertext/check');
            this._error = '';
        } catch (e) {
            this._error = e.message;
        }
        this._busy = false;
        this._render();
    }

    _date(iso) {
        const d = new Date(iso);
        const locale = (window.__GRAV_I18N && window.__GRAV_I18N.locale) || undefined;
        try {
            return isNaN(d) ? String(iso) : d.toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' });
        } catch (e) {
            return d.toLocaleString();
        }
    }

    _render() {
        let body = '';
        if (this._error) {
            body += `<div class="sts-box sts-warn" role="alert">${esc(t('LOAD_ERROR', { error: this._error }))}</div>`;
        }
        const d = this._data;
        if (d) {
            const check = d.key_check;
            if (check) {
                const key = 'KEYCHECK_' + String(check.status).toUpperCase();
                const status = check.http_status ? 'HTTP ' + check.http_status + (check.error_code ? ', ' + check.error_code : '') : (check.error_code || '?');
                body += `<div class="sts-box sts-${TONES[check.status] || 'warn'}" data-testid="supertext-key-check" data-status="${esc(check.status)}">
                    <div>${withLinks(t(EN[key] ? key : 'KEYCHECK_ERROR', { status }))}</div>
                    <div class="sts-time">${esc(t('CHECKED_AT', { time: this._date(check.checked_at) }))}</div>
                </div>`;
            } else {
                body += `<div class="sts-box sts-muted" data-testid="supertext-key-check" data-status="none">${esc(t('NOT_CHECKED'))}</div>`;
            }
            const env = d.reports && d.reports.environment;
            if (env && d.reports.enabled !== d.reports.setting) {
                body += `<p class="sts-note">${esc(t(d.reports.enabled ? 'REPORTS_ENV_ON' : 'REPORTS_ENV_OFF', { value: env }))}</p>`;
            }
            body += `<button type="button" class="sts-btn" data-action="check" ${this._busy ? 'disabled' : ''}>${esc(t(this._busy ? 'CHECKING' : 'CHECK_AGAIN'))}</button>`;
        }

        this.innerHTML = `
            <style>
                ${TAG} { display: block; font-size: 13px; color: var(--foreground, #111827); }
                ${TAG} .sts-box { border-radius: 8px; padding: 10px 12px; margin: 0 0 10px; border: 1px solid currentColor; background: color-mix(in srgb, currentColor 7%, transparent); }
                ${TAG} .sts-box a { color: inherit; text-decoration: underline; }
                ${TAG} .sts-time { margin-top: 4px; font-size: 12px; opacity: .8; }
                ${TAG} .sts-ok { color: #15803d; }
                ${TAG} .sts-warn { color: #b45309; }
                ${TAG} .sts-error { color: var(--destructive, #dc2626); }
                ${TAG} .sts-muted { color: var(--muted-foreground, #6b7280); }
                ${TAG} .sts-note { margin: 0 0 10px; color: var(--muted-foreground, #6b7280); }
                ${TAG} .sts-btn { border: 1px solid var(--border, #d1d5db); background: var(--background, #fff); color: var(--foreground, #111827); border-radius: 6px; padding: 6px 12px; font: inherit; font-weight: 500; cursor: pointer; }
                ${TAG} .sts-btn[disabled] { opacity: .5; cursor: not-allowed; }
            </style>
            ${body}`;

        const button = this.querySelector('[data-action="check"]');
        if (button) button.addEventListener('click', () => this._check());
    }
}

if (!customElements.get(TAG)) {
    customElements.define(TAG, SupertextStatusField);
}
