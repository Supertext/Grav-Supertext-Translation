/**
 * Supertext translation panel for the Admin2 page editor.
 *
 * Admin2 loads this file through GET /api/v1/gpm/plugins/supertext-translation/panel-script
 * when an editor clicks the Supertext button in the page editor toolbar. It defines the
 * custom element named in window.__GRAV_PANEL_TAG and sets its `route`, `lang` and `type`
 * attributes for the page being edited. The element fires `close` to close the panel.
 */
const TAG = window.__GRAV_PANEL_TAG || 'grav-supertext-translation--panel';

const STATE_LABELS = {
    missing: { text: 'Not translated yet', tone: 'muted' },
    current: { text: 'Up to date', tone: 'ok' },
    outdated: { text: 'Source changed since', tone: 'info' },
    edited: { text: 'Edited after translation', tone: 'warn' },
    manual: { text: 'Exists, not from Supertext', tone: 'warn' },
};

const FIELD_LABELS = {
    title: 'title',
    menu: 'menu label',
    'metadata.description': 'meta description',
    'metadata.keywords': 'meta keywords',
    summary: 'summary',
};

const RESULT_TEXT = {
    created: 'translation created',
    updated: 'translation updated',
    skipped: 'kept as it is',
    error: 'not translated',
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
        this._render();
        this._load();
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
            throw new Error(detail || `The server answered with HTTP ${response.status}.`);
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
        return isNaN(d) ? '' : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
    }

    _render() {
        const s = this._status;
        let body = '';

        if (this._error) {
            body += `<div class="st-box st-error" role="alert">${this._esc(this._error)}</div>`;
        }

        if (!s && !this._error) {
            body += `<p class="st-muted">Loading…</p>`;
        }

        if (s) {
            body += `<p class="st-intro">Translate <strong>${this._esc(s.title)}</strong> from
                <strong>${this._esc(s.source.name)}</strong> into:</p>`;

            if (!s.api_key_configured) {
                body += `<div class="st-box st-warn">No Supertext API key is configured yet. An administrator
                    can add it under Plugins → Supertext Translation. No Supertext account yet?
                    <a href="https://www.supertext.com/person/en/account/signin" target="_blank" rel="noopener noreferrer">Create one at supertext.com</a>.
                    Generate the API key at <a href="https://www.supertext.com/en/integrations/api" target="_blank" rel="noopener noreferrer">supertext.com → Integrations → API</a>
                    (requires the Admin role).</div>`;
            }
            if (!s.languages.length) {
                body += `<div class="st-box st-info">This site has no other languages. Add languages in the
                    system configuration first.</div>`;
            }

            body += '<ul class="st-langs">';
            for (const l of s.languages) {
                const label = STATE_LABELS[l.state] || { text: l.state, tone: 'muted' };
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
                const lines = this._confirm.map((l) => l.state === 'edited'
                    ? `<li><strong>${this._esc(l.name)}</strong> was edited after it was translated.</li>`
                    : `<li><strong>${this._esc(l.name)}</strong> already exists and was not made with Supertext.</li>`).join('');
                body += `<div class="st-box st-warn" role="alert">
                    <strong>Replace existing ${this._confirm.length > 1 ? 'translations' : 'translation'}?</strong>
                    <ul class="st-confirm">${lines}</ul>
                    <p>Translating again replaces that text with a new translation of the ${this._esc(s.source.name)} page.</p>
                    <div class="st-actions">
                        <button type="button" class="st-btn st-danger" data-action="overwrite">Replace</button>
                        <button type="button" class="st-btn" data-action="cancel">Cancel</button>
                    </div>
                </div>`;
            } else {
                const n = this._selected.size;
                body += `<div class="st-actions">
                    <button type="button" class="st-btn st-primary" data-action="translate" ${n && !this._busy && s.api_key_configured ? '' : 'disabled'}>
                        ${this._busy ? '<span class="st-spinner" aria-hidden="true"></span> Translating…' : `Translate${n ? ` into ${n} language${n > 1 ? 's' : ''}` : ''}`}
                    </button>
                </div>`;
                if (this._busy) {
                    body += `<p class="st-muted">Supertext is translating the page. This usually takes
                        10 to 60 seconds; you can keep editing in the meantime.</p>`;
                }
            }

            if (this._results) {
                body += '<ul class="st-results">';
                for (const r of this._results) {
                    const tone = r.result === 'error' ? 'error' : r.result === 'skipped' ? 'warn' : 'ok';
                    body += `<li class="st-${tone}"><strong>${this._esc(r.name)}:</strong> ${this._esc(RESULT_TEXT[r.result] || r.result)}${r.message ? ` — ${this._esc(r.message)}` : ''}</li>`;
                }
                body += '</ul>';
                if (this._results.some((r) => r.result === 'created')) {
                    body += `<p class="st-muted">New translations are saved unpublished unless your administrator
                        changed that. Switch the editor to the language to review and publish them.</p>`;
                }
            }

            const fields = (s.translated_fields || []).map((f) => FIELD_LABELS[f] || f).join(', ');
            body += `<p class="st-foot">Supertext translates the page content${fields ? ` and these fields: ${this._esc(fields)}` : ''}.
                Translations edited by hand are never replaced without asking.</p>`;
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
                <h2>Supertext translation</h2>
                <button type="button" class="st-close" data-action="close" aria-label="Close">×</button>
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
