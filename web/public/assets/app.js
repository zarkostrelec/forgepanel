/* ForgePanel SPA — vanilla ES2024, bez build alata. UI je samo potrošač REST API-ja. */

// ---------------------------------------------------------------- state
const state = {
    token: localStorage.getItem('fp_token'),
    me: null,
    lang: {},
    langCode: localStorage.getItem('fp_lang') ?? 'hr',
    theme: localStorage.getItem('fp_theme') ?? 'light',
    activeTasks: new Map(),
    aiThread: (() => { try { return JSON.parse(localStorage.getItem('fp_ai_thread') || '[]'); } catch { return []; } })(),
    aiOpen: false,
    railExpanded: localStorage.getItem('fp_rail') === '1',
    monTimer: null,
    monRange: localStorage.getItem('fp_mon_range') || '2h',
    monRefresh: localStorage.getItem('fp_mon_refresh') || '0',
};

const $app = document.getElementById('app');

// ---------------------------------------------------------------- i18n + formati (europski)
async function loadLang() {
    const res = await fetch(`/lang/${state.langCode}.json`);
    state.lang = res.ok ? await res.json() : {};
}

// White-label: branding po hostu (reseller domena dobiva svoj naziv/accent/logo)
async function loadBranding() {
    try {
        const res = await fetch('/api/v1/branding');
        const b = (await res.json()).data ?? {};
        state.branding = b;
        if (b.accent) document.documentElement.style.setProperty('--accent', b.accent);
        if (b.panel_name) document.title = b.panel_name;
    } catch { state.branding = { panel_name: 'ForgePanel' }; }
}
const brandName = () => state.branding?.panel_name ?? 'ForgePanel';
const t = (key) => state.lang[key] ?? key;

const fmtDate = (s) => {
    if (!s) return '—';
    const d = new Date(String(s).replace(' ', 'T'));
    return new Intl.DateTimeFormat('hr-HR', { dateStyle: 'short', timeStyle: 'short' }).format(d);
};
const fmtTime = (s) => {
    if (!s) return '—';
    const d = new Date(String(s).replace(' ', 'T'));
    return new Intl.DateTimeFormat('hr-HR', { timeStyle: 'medium' }).format(d);
};
const timeAgo = (s) => {
    if (!s) return '—';
    const sec = Math.max(0, (Date.now() - new Date(String(s).replace(' ', 'T'))) / 1000);
    if (sec < 60) return 'sad';
    if (sec < 3600) return `prije ${Math.floor(sec / 60)} min`;
    if (sec < 86400) return `prije ${Math.floor(sec / 3600)} h`;
    return `prije ${Math.floor(sec / 86400)} d`;
};
const fmtBytes = (n) => {
    n = Number(n) || 0;
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return `${new Intl.NumberFormat('hr-HR', { maximumFractionDigits: 1 }).format(n)} ${units[i]}`;
};

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

// base64url ↔ ArrayBuffer (WebAuthn)
const b64u = {
    enc: (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''),
    dec: (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0)),
};

// ---------------------------------------------------------------- ikone (geometrijski strokeovi, docs/design/ui.jsx)
const ICONS = {
    grid: '<rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/>',
    globe: '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17"/><path d="M12 3.5c2.6 2.3 2.6 14.7 0 17c-2.6-2.3-2.6-14.7 0-17z"/>',
    folder: '<path d="M3.5 6.5a2 2 0 0 1 2-2h4l2 2.5h7a2 2 0 0 1 2 2v8.5a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z"/>',
    pulse: '<path d="M3 12h4l2-5 4 10 2-5h6"/>',
    activity: '<path d="M3 13h3.5l2.5-7 4 12 2.5-7H21"/>',
    shield: '<path d="M12 3.5l7 2.5v6c0 4.4-3 7.5-7 8.5c-4-1-7-4.1-7-8.5v-6z"/>',
    terminal: '<path d="M5 8l4 4-4 4"/><path d="M12 17h7"/>',
    search: '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/>',
    sparkle: '<path d="M12 4l1.8 5.4L19 11l-5.2 1.6L12 18l-1.8-5.4L5 11l5.2-1.6z"/>',
    spark: '<path d="M12 4l1.8 5.4L19 11l-5.2 1.6L12 18l-1.8-5.4L5 11l5.2-1.6z"/>',
    bell: '<path d="M6 16v-5a6 6 0 0 1 12 0v5l1.5 2.5h-15z"/><path d="M10 21h4"/>',
    gear: '<circle cx="12" cy="12" r="3.5"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/>',
    chevR: '<path d="M9 5l7 7-7 7"/>',
    chevD: '<path d="M5 9l7 7 7-7"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    play: '<path d="M7 5l12 7-12 7z"/>',
    refresh: '<path d="M19 12a7 7 0 1 1-2-5"/><path d="M17 3v4h4"/>',
    lock: '<rect x="5.5" y="10.5" width="13" height="9" rx="2"/><path d="M8.5 10.5v-3a3.5 3.5 0 0 1 7 0v3"/>',
    dots: '<circle cx="5" cy="12" r="1.3" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.3" fill="currentColor" stroke="none"/><circle cx="19" cy="12" r="1.3" fill="currentColor" stroke="none"/>',
    file: '<path d="M6 3.5h8l4 4v13h-12z"/><path d="M14 3.5v4h4"/>',
    db: '<ellipse cx="12" cy="6" rx="7.5" ry="3"/><path d="M4.5 6v12c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3V6"/><path d="M4.5 12c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3"/>',
    server: '<rect x="3.5" y="4.5" width="17" height="6.5" rx="1.5"/><rect x="3.5" y="13" width="17" height="6.5" rx="1.5"/><circle cx="7.5" cy="7.75" r="0.8" fill="currentColor" stroke="none"/><circle cx="7.5" cy="16.25" r="0.8" fill="currentColor" stroke="none"/>',
    x: '<path d="M6 6l12 12M18 6L6 18"/>',
    check: '<path d="M5 13l4.5 4.5L19 7"/>',
    arrowUR: '<path d="M7 17L17 7M9 7h8v8"/>',
    branch: '<circle cx="6" cy="6" r="2.2"/><circle cx="6" cy="18" r="2.2"/><circle cx="18" cy="8" r="2.2"/><path d="M6 8.2v7.6M18 10.2c0 4-5 3.8-9.8 5.4"/>',
    clock: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7v5.5l3.5 2"/>',
    zap: '<path d="M13 3L5 14h6l-1 7 8-11h-6z"/>',
    mail: '<rect x="3.5" y="5.5" width="17" height="13" rx="2"/><path d="M4 7l8 6 8-6"/>',
    download: '<path d="M12 4v11M7 11l5 5 5-5M5 20h14"/>',
    upload: '<path d="M12 15V4M7 9l5-5 5 5M5 20h14"/>',
    key: '<circle cx="8" cy="12" r="4.5"/><path d="M12.5 12H21M18 12v3.5M15 12v2.5"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2.5M12 19v2.5M2.5 12H5M19 12h2.5M5.2 5.2l1.8 1.8M17 17l1.8 1.8M18.8 5.2 17 7M7 17l-1.8 1.8"/>',
    moon: '<path d="M20 13.5A8 8 0 0 1 10.5 4 8 8 0 1 0 20 13.5z"/>',
    user: '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c1.2-3.5 3.8-5 7-5s5.8 1.5 7 5"/>',
    logout: '<path d="M14 4h-8v16h8"/><path d="M10 12h11M17.5 8.5 21 12l-3.5 3.5"/>',
    box: '<path d="M12 2.5 21 7v10l-9 4.5L3 17V7z"/><path d="M3 7l9 4.5L21 7M12 11.5V21.5"/>',
    wall: '<rect x="3" y="4" width="18" height="16" rx="1"/><path d="M3 9h18M3 14h18M8 4v5M16 9v5M8 14v6"/>',
    cloud: '<path d="M7 18a4 4 0 0 1 0-8 5 5 0 0 1 9.6-1.3A3.5 3.5 0 0 1 17 18z"/>',
    menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
    history: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 4v4h4"/><path d="M12 8v4l3 2"/>',
    users: '<circle cx="9" cy="8" r="3"/><path d="M3 20c1-3.3 3.2-5 6-5s5 1.7 6 5"/><path d="M16 5.5a3 3 0 0 1 0 5.8M18 20c-.3-2-1-3.5-2-4.5"/>',
    archive: '<rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9"/><path d="M10 13h4"/>',
    dns: '<circle cx="12" cy="5" r="2.2"/><circle cx="5" cy="19" r="2.2"/><circle cx="19" cy="19" r="2.2"/><path d="M12 7.2V12m0 0-5.2 5M12 12l5.2 5"/>',
};
const icon = (name, size = 16) => `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0" aria-hidden="true">${ICONS[name] ?? '<circle cx="12" cy="12" r="8"/>'}</svg>`;
const dot = (tone = 'ok', pulse = false) => `<span class="dot ${tone}${pulse ? ' pulse' : ''}"></span>`;

// ---------------------------------------------------------------- API klijent
async function api(path, { method = 'GET', body } = {}) {
    const res = await fetch(`/api/v1${path}`, {
        method,
        headers: {
            ...(state.token ? { Authorization: `Bearer ${state.token}` } : {}),
            ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
    });
    const json = await res.json().catch(() => ({ ok: false, error: 'bad_response' }));
    if (res.status === 401 && state.me) { logoutLocal(); return Promise.reject(new Error('unauthenticated')); }
    if (!json.ok) throw new Error(json.error ?? `http_${res.status}`);
    return json.data;
}

function logoutLocal() {
    state.token = null;
    state.me = null;
    localStorage.removeItem('fp_token');
    renderLogin();
}

// ---------------------------------------------------------------- toast
function toast(msg, kind = 'ok') {
    let wrap = document.querySelector('.toasts');
    if (!wrap) { wrap = document.createElement('div'); wrap.className = 'toasts'; document.body.append(wrap); }
    const el = document.createElement('div');
    el.className = `toast ${kind}`;
    el.textContent = msg;
    wrap.append(el);
    setTimeout(() => el.remove(), 4500);
}

// ---------------------------------------------------------------- <fp-modal> Web Component
class FpModal extends HTMLElement {
    connectedCallback() {
        this.classList.add('overlay');
        this.addEventListener('click', (e) => { if (e.target === this) this.close(); });
        this._esc = (e) => { if (e.key === 'Escape') this.close(); };
        document.addEventListener('keydown', this._esc);
        this.querySelector('input, select, textarea, button')?.focus();
    }
    disconnectedCallback() { document.removeEventListener('keydown', this._esc); }
    close() { this.remove(); }
}
customElements.define('fp-modal', FpModal);

function openModal(html, { wide = false } = {}) {
    const modal = document.createElement('fp-modal');
    modal.innerHTML = `<div class="dialog${wide ? ' wide' : ''}">${html}</div>`;
    document.body.append(modal);
    modal.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => modal.close()));
    return modal;
}

// ---------------------------------------------------------------- <fp-palette> — Ctrl+K command palette
class FpPalette extends HTMLElement {
    async open() {
        if (this.isConnected) return;
        this.classList.add('overlay', 'top');
        this.innerHTML = `<div class="palette-dialog">
            <div class="palette-head">${icon('search')}
                <input class="palette-input" placeholder="${t('palette.placeholder')}" autocomplete="off">
                <span class="kbd">esc</span></div>
            <div class="palette-list"></div>
        </div>`;
        document.body.append(this);

        this.actions = [
            { group: t('palette.actions'), ic: 'plus', label: t('vhost.create'), run: () => createVhostModal() },
            { group: t('palette.actions'), ic: 'db', label: t('db.create'), run: () => createDbModal() },
            { group: t('palette.actions'), ic: state.theme === 'dark' ? 'sun' : 'moon', label: t('palette.theme'), run: toggleTheme },
            { group: t('palette.actions'), ic: 'logout', label: t('auth.logout'), run: doLogout },
            ...RAIL.filter(railVisible).map((r) => ({
                group: t('palette.nav'), ic: r.icon, label: `${t('palette.goto')} ${t(r.label)}`, kbd: `G ${r.key.toUpperCase()}`, go: `#/${r.pages[0]}`,
            })),
            { group: t('palette.nav'), ic: 'user', label: t('profile.title'), go: '#/profile' },
        ];
        if (state.me.role === 'admin') this.actions.splice(4, 0,
            { group: t('palette.actions'), ic: 'sparkle', label: t('assistant.ask'), run: () => toggleAiDrawer(true) });
        try {
            (await api('/vhosts')).forEach((v) =>
                this.actions.push({ group: t('nav.websites'), ic: 'globe', label: v.domain, go: `#/websites/${v.id}` }));
        } catch { /* offline lista i dalje radi */ }

        this.input = this.querySelector('input');
        this.list = this.querySelector('.palette-list');
        this.idx = 0;
        this.input.addEventListener('input', () => { this.idx = 0; this.renderList(); });
        this.input.addEventListener('keydown', (e) => {
            const items = this.filtered();
            if (e.key === 'ArrowDown') { this.idx = Math.min(this.idx + 1, items.length - 1); this.renderList(); e.preventDefault(); }
            if (e.key === 'ArrowUp') { this.idx = Math.max(this.idx - 1, 0); this.renderList(); e.preventDefault(); }
            if (e.key === 'Enter' && items[this.idx]) this.pick(items[this.idx]);
            if (e.key === 'Escape') this.remove();
        });
        this.addEventListener('click', (e) => { if (e.target === this) this.remove(); });
        this.renderList();
        this.input.focus();
    }
    filtered() {
        const q = (this.input?.value ?? '').toLowerCase().trim();
        return this.actions.filter((a) => a.label.toLowerCase().includes(q)).slice(0, 14);
    }
    renderList() {
        let lastGroup = null;
        this.list.innerHTML = this.filtered().map((a, i) => {
            const head = a.group !== lastGroup ? `<div class="palette-group">${esc(a.group)}</div>` : '';
            lastGroup = a.group;
            return `${head}<div class="palette-item${i === this.idx ? ' active' : ''}" data-i="${i}">
                <span class="pi-icon">${icon(a.ic ?? 'chevR')}</span>
                <span class="pi-label">${esc(a.label)}</span>
                <span class="pi-kbd">${a.kbd ? `<span class="kbd">${a.kbd}</span>` : ''}</span>
            </div>`;
        }).join('') || `<div class="empty">${t('palette.empty')}</div>`;
        this.list.querySelectorAll('.palette-item').forEach((el) =>
            el.addEventListener('click', () => this.pick(this.filtered()[Number(el.dataset.i)])));
    }
    pick(action) {
        this.remove();
        if (action.go) location.hash = action.go;
        if (action.run) action.run();
    }
}
customElements.define('fp-palette', FpPalette);
const palette = new FpPalette();

// globalne kratice: Ctrl/⌘+K paleta, Ctrl/⌘+J AI, G+slovo navigacija
let gPending = false;
document.addEventListener('keydown', (e) => {
    if (!state.me) return;
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); palette.open(); return; }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'j') { e.preventDefault(); toggleAiDrawer(); return; }
    const inInput = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName);
    if (inInput || e.ctrlKey || e.metaKey || e.altKey) return;
    if (e.key.toLowerCase() === 'g') { gPending = true; setTimeout(() => { gPending = false; }, 900); return; }
    if (gPending) {
        const target = RAIL.filter(railVisible).find((r) => r.key === e.key.toLowerCase());
        if (target) { location.hash = `#/${target.pages[0]}`; gPending = false; }
    }
});

// ---------------------------------------------------------------- task praćenje (SSE) + tray
function watchTask(taskId, label) {
    state.activeTasks.set(taskId, { id: taskId, label, status: 'running', progress: 0 });
    renderTray();
    streamTask(taskId, label);
}

async function streamTask(taskId, label) {
    // SSE kroz fetch (EventSource ne podržava Authorization header) — čita event-stream ručno
    try {
        const res = await fetch(`/api/v1/tasks/${taskId}/stream`, {
            headers: { Authorization: `Bearer ${state.token}` },
        });
        const reader = res.body.getReader();
        const decoder = new TextDecoder();
        let buf = '';
        for (;;) {
            const { done, value } = await reader.read();
            if (done) break;
            buf += decoder.decode(value, { stream: true });
            let sep;
            while ((sep = buf.indexOf('\n\n')) >= 0) {
                const chunk = buf.slice(0, sep); buf = buf.slice(sep + 2);
                const data = chunk.split('\n').find((l) => l.startsWith('data: '))?.slice(6);
                if (!data) continue;
                const ev = JSON.parse(data);
                if (ev.status) {
                    state.activeTasks.set(taskId, { id: taskId, label, status: ev.status, progress: ev.progress ?? 0, output: ev.output });
                    renderTray();
                    if (['done', 'failed'].includes(ev.status)) {
                        toast(`${label}: ${t('task.' + ev.status)}`, ev.status === 'done' ? 'ok' : 'err');
                        setTimeout(() => { state.activeTasks.delete(taskId); renderTray(); }, 8000);
                        route();
                        return;
                    }
                }
            }
        }
    } catch { /* stream prekinut — tray ostaje na zadnjem stanju */ }
}

function renderTray() {
    const dot = document.querySelector('.tray-btn .dot');
    if (dot) dot.style.display = state.activeTasks.size ? '' : 'none';
    const pop = document.querySelector('.tray-pop');
    if (pop) pop.innerHTML = trayHtml();
}

const trayHtml = () => [...state.activeTasks.values()].reverse().map((task) => `
    <div class="tray-item">
        <div class="row">
            <span class="op">${esc(task.label)}</span>
            <span class="badge ${task.status === 'done' ? 'ok' : task.status === 'failed' ? 'err' : 'info'}">${t('task.' + task.status)}</span>
        </div>
        <div class="progress"><div style="width:${task.progress}%"></div></div>
    </div>`).join('') || `<div class="empty">${t('task.pending')}: 0</div>`;

// ---------------------------------------------------------------- login
function renderLogin(step = 'login', preToken = null, methods = ['totp']) {
    const hasTotp = methods.includes('totp');
    const hasKey = methods.includes('webauthn') && navigator.credentials;
    $app.innerHTML = `
    <div class="login-wrap"><div class="card login-card">
        <div class="login-brand">
            <div class="mark">${state.branding?.logo_url
                ? `<img src="${esc(state.branding.logo_url)}" alt="">`
                : icon('zap', 20)}</div>
            <div class="name">${esc(brandName())}</div>
        </div>
        <div class="alert err" hidden></div>
        ${step === 'login' ? `
            <form id="f">
                <div class="field"><label>${t('auth.email')}</label><input name="email" type="email" required autocomplete="username"></div>
                <div class="field"><label>${t('auth.password')}</label><input name="password" type="password" required autocomplete="current-password"></div>
                <button class="btn primary" style="width:100%">${t('auth.login')}</button>
            </form>` : `
            ${hasTotp ? `<form id="f">
                <div class="field"><label>${t('auth.twofa_code')}</label><input name="code" inputmode="numeric" pattern="\\d{6}" maxlength="6" required autofocus class="mono"></div>
                <button class="btn primary" style="width:100%">${t('auth.login')}</button>
            </form>` : ''}
            ${hasKey ? `<button class="btn${hasTotp ? '' : ' primary'}" id="wakey" style="width:100%${hasTotp ? ';margin-top:10px' : ''}">${icon('key')}${t('auth.use_security_key')}</button>` : ''}`}
    </div></div>`;

    const form = document.getElementById('f');
    const alertEl = $app.querySelector('.alert');
    const showErr = (err) => {
        alertEl.hidden = false;
        alertEl.textContent = t('auth.' + err.message) !== 'auth.' + err.message ? t('auth.' + err.message) : err.message;
    };

    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const data = Object.fromEntries(new FormData(form));
        try {
            if (step === 'login') {
                const r = await api('/auth/login', { method: 'POST', body: data });
                state.token = r.token;
                if (r.status === 'twofa_required') return renderLogin('twofa', r.token, r.methods ?? ['totp']);
                localStorage.setItem('fp_token', r.token);
                await enter();
            } else {
                state.token = preToken;
                await api('/auth/twofa', { method: 'POST', body: { code: data.code } });
                localStorage.setItem('fp_token', preToken);
                await enter();
            }
        } catch (err) { showErr(err); }
    });

    const webauthnAttempt = async () => {
        state.token = preToken;
        const o = await api('/auth/webauthn/login/options', { method: 'POST' });
        const cred = await navigator.credentials.get({ publicKey: {
            challenge: b64u.dec(o.challenge),
            rpId: o.rp_id,
            allowCredentials: o.allow.map((id) => ({ type: 'public-key', id: b64u.dec(id) })),
            userVerification: 'discouraged',
            timeout: 60000,
        } });
        await api('/auth/webauthn/login', { method: 'POST', body: {
            credential_id: cred.id,
            authenticator_data: b64u.enc(cred.response.authenticatorData),
            client_data_json: b64u.enc(cred.response.clientDataJSON),
            signature: b64u.enc(cred.response.signature),
        } });
        localStorage.setItem('fp_token', preToken);
        await enter();
    };
    if (hasKey && step === 'twofa') {
        document.getElementById('wakey').addEventListener('click', () => webauthnAttempt().catch(showErr));
        if (!hasTotp) webauthnAttempt().catch(showErr); // jedina metoda → odmah traži ključ
    }
}

async function doLogout() {
    try { await api('/auth/logout', { method: 'POST' }); } catch { /* svejedno čistimo */ }
    logoutLocal();
}

// ---------------------------------------------------------------- shell
function toggleTheme() {
    state.theme = state.theme === 'dark' ? 'light' : 'dark';
    localStorage.setItem('fp_theme', state.theme);
    document.documentElement.dataset.theme = state.theme;
}

// rail: grupe modula; grupe s više stranica dobivaju tab strip (tabsHtml)
const RAIL = [
    { id: 'dashboard', icon: 'grid', label: 'nav.dashboard', key: 'd', pages: ['dashboard'] },
    { id: 'websites', icon: 'globe', label: 'nav.websites', key: 's', pages: ['websites'] },
    { id: 'files', icon: 'folder', label: 'nav.files', key: 'f', pages: ['files'] },
    { id: 'databases', icon: 'db', label: 'nav.databases', key: 'b', pages: ['databases'] },
    { id: 'mail', icon: 'mail', label: 'nav.mail', key: 'e', pages: ['mail'] },
    { id: 'docker', icon: 'box', label: 'nav.docker', key: 'k', pages: ['docker'] },
    { id: 'backups', icon: 'download', label: 'nav.backups', key: 'a', pages: ['backups'] },
    { id: 'monitoring', icon: 'pulse', label: 'nav.monitoring', key: 'm', pages: ['monitoring', 'tasks'] },
    { id: 'protect', icon: 'shield', label: 'nav.protect', key: 'p', pages: ['ssl', 'dns', 'cloudflare', 'security', 'firewall'] },
    { id: 'server', icon: 'server', label: 'nav.server', key: 'u', roles: ['admin'], pages: ['updates', 'config'] },
    { id: 'users', icon: 'users', label: 'nav.users', key: 'o', roles: ['admin', 'reseller'], pages: ['users'] },
];
const railVisible = (r) => !r.roles || r.roles.includes(state.me?.role);

const TAB_GROUPS = {
    monitoring: [['monitoring', 'nav.monitoring', 'pulse'], ['tasks', 'nav.tasks', 'clock']],
    protect: [['ssl', 'nav.ssl', 'lock'], ['dns', 'nav.dns', 'globe'], ['cloudflare', 'nav.cloudflare', 'cloud', 'admin'], ['security', 'nav.security', 'shield'], ['firewall', 'nav.firewall', 'wall', 'admin']],
    server: [['updates', 'nav.updates', 'refresh'], ['config', 'nav.config', 'history']],
};

// tab strip za grupirane stranice (Zaštita: SSL · DNS · Sigurnost · Firewall, itd.)
function tabsHtml(groupId, activePage) {
    const tabs = (TAB_GROUPS[groupId] ?? []).filter(([, , , role]) => !role || role === state.me.role);
    if (tabs.length < 2) return '';
    return `<nav class="tabs">${tabs.map(([page, key, ic]) =>
        `<a href="#/${page}" class="${page === activePage ? 'active' : ''}">${icon(ic)}${t(key)}</a>`).join('')}</nav>`;
}

function renderShell() {
    const initials = state.me.email.slice(0, 2).toUpperCase();
    const isAdmin = state.me.role === 'admin';
    $app.innerHTML = `
    <div class="shell">
        <nav class="rail${state.railExpanded ? ' expanded' : ''}" aria-label="Glavna navigacija">
            <div class="rail-item">
                <button class="rail-btn rail-toggle" id="railtoggle" aria-label="${t('nav.toggle')}">${icon('menu')}<span class="rail-label">${t('nav.collapse')}</span></button>
                <span class="rail-tip">${t('nav.toggle')}</span>
            </div>
            <div class="rail-logo" title="${esc(brandName())}">${state.branding?.logo_url
                ? `<img src="${esc(state.branding.logo_url)}" alt="${esc(brandName())}">`
                : icon('zap')}<span class="rail-label">${esc(brandName())}</span></div>
            ${RAIL.filter(railVisible).map((r) => `
            <div class="rail-item">
                <button class="rail-btn" data-rail="${r.id}" data-go="#/${r.pages[0]}" aria-label="${t(r.label)}">${icon(r.icon)}<span class="rail-label">${t(r.label)}</span></button>
                <span class="rail-tip">${t(r.label)} <span class="kbd-hint">G ${r.key.toUpperCase()}</span></span>
            </div>`).join('')}
            <div class="rail-spacer"></div>
            <div class="rail-sep"></div>
            <div class="rail-item">
                <button class="rail-btn" data-go="#/profile" data-rail="profile" aria-label="${t('profile.title')}">${icon('key')}<span class="rail-label">${t('profile.title')}</span></button>
                <span class="rail-tip">${t('profile.title')}</span>
            </div>
            <button class="rail-avatar" aria-label="${esc(state.me.email)}">${esc(initials)}</button>
        </nav>
        <div class="main">
            <header class="topbar">
                <div class="topbar-host">${dot('ok', true)}<span class="host-name">${esc(location.hostname || brandName())}</span></div>
                <div class="crumbs-bar" id="crumbs"></div>
                <button class="search-btn">${icon('search')}
                    <span class="search-label">${t('palette.placeholder')}</span>
                    <span class="keys"><span class="kbd">Ctrl</span><span class="kbd">K</span></span></button>
                ${isAdmin ? `<button class="ai-btn" id="aibtn" title="Forge AI (Ctrl+J)">${icon('sparkle')}<span class="ai-label">Forge AI</span></button>` : ''}
                <button class="icon-btn tray-btn" aria-label="${t('nav.tasks')}">${icon('activity')}<span class="dot"></span></button>
                <button class="icon-btn theme-btn" aria-label="Tema">${icon(state.theme === 'dark' ? 'sun' : 'moon')}</button>
            </header>
            <div class="body-row">
                <main class="content"></main>
                <aside class="ai-drawer" id="aidrawer" hidden></aside>
            </div>
        </div>
    </div>`;

    $app.querySelectorAll('[data-go]').forEach((b) => b.addEventListener('click', () => { location.hash = b.dataset.go; }));
    $app.querySelector('#railtoggle').addEventListener('click', () => {
        state.railExpanded = !state.railExpanded;
        localStorage.setItem('fp_rail', state.railExpanded ? '1' : '0');
        $app.querySelector('.rail').classList.toggle('expanded', state.railExpanded);
    });
    $app.querySelector('.search-btn').addEventListener('click', () => palette.open());
    $app.querySelector('#aibtn')?.addEventListener('click', () => toggleAiDrawer());
    $app.querySelector('.theme-btn').addEventListener('click', (e) => {
        toggleTheme();
        e.currentTarget.innerHTML = icon(state.theme === 'dark' ? 'sun' : 'moon');
    });
    $app.querySelector('.rail-avatar').addEventListener('click', (e) => {
        e.stopPropagation();
        const existing = document.querySelector('.user-pop');
        if (existing) return existing.remove();
        const pop = document.createElement('div');
        pop.className = 'user-pop';
        pop.innerHTML = `
            <div class="who"><div class="em">${esc(state.me.email)}</div><div class="ro">${esc(state.me.role)}</div></div>
            <button data-act="profile">${icon('user')}${t('profile.title')}</button>
            <button data-act="logout">${icon('logout')}${t('auth.logout')}</button>`;
        document.body.append(pop);
        pop.querySelector('[data-act="profile"]').addEventListener('click', () => { pop.remove(); location.hash = '#/profile'; });
        pop.querySelector('[data-act="logout"]').addEventListener('click', () => { pop.remove(); doLogout(); });
        setTimeout(() => document.addEventListener('click', () => pop.remove(), { once: true }));
    });
    $app.querySelector('.tray-btn').addEventListener('click', (e) => {
        e.stopPropagation();
        const existing = document.querySelector('.tray-pop');
        if (existing) return existing.remove();
        const pop = document.createElement('div');
        pop.className = 'tray-pop';
        pop.innerHTML = trayHtml();
        document.body.append(pop);
        setTimeout(() => document.addEventListener('click', () => pop.remove(), { once: true }));
    });
    renderTray();
    if (state.aiOpen) renderAiDrawer();
}

const main = () => $app.querySelector('.content');

function setActive(page, crumbs = null) {
    const group = RAIL.find((r) => r.pages.includes(page));
    $app.querySelectorAll('.rail-btn[data-rail]').forEach((b) =>
        b.classList.toggle('active', b.dataset.rail === (group?.id ?? page)));
    const titleKey = (TAB_GROUPS[group?.id] ?? []).find(([p]) => p === page)?.[1] ?? group?.label ?? `nav.${page}`;
    setCrumbs(crumbs ?? [t(titleKey) === `nav.${page}` ? t('profile.title') : t(titleKey)]);
    document.querySelector('.user-pop')?.remove();
}

function setCrumbs(parts) {
    const el = document.getElementById('crumbs');
    if (el) el.innerHTML = parts.map((p) => `${icon('chevR')}<span class="crumb">${esc(p)}</span>`).join('');
}

// ---------------------------------------------------------------- Forge AI drawer (⌘J / Ctrl+J)
function toggleAiDrawer(forceOpen = null) {
    if (state.me?.role !== 'admin') return;
    state.aiOpen = forceOpen ?? !state.aiOpen;
    const drawer = document.getElementById('aidrawer');
    if (!drawer) return;
    drawer.hidden = !state.aiOpen;
    document.getElementById('aibtn')?.classList.toggle('active', state.aiOpen);
    if (state.aiOpen) renderAiDrawer();
}

// AI odgovor → HTML: ```sh/```bash blokovi postaju kartice s "Izvrši" (samo lokalni mod)
function aiMessageHtml(text, canExec) {
    const parts = String(text).split(/```(\w*)\n?([\s\S]*?)```/g);
    let html = '';
    for (let i = 0; i < parts.length; i += 3) {
        const prose = parts[i];
        if (prose) html += `<div class="ai-prose">${esc(prose).replace(/\n/g, '<br>')}</div>`;
        const lang = parts[i + 1];
        const code = parts[i + 2];
        if (code == null) continue;
        const cmd = code.replace(/\n+$/, '');
        const isShell = /^(sh|bash|shell|console|)$/i.test(lang || '') && cmd.trim() !== '';
        if (isShell && canExec) {
            html += `<div class="ai-cmd"><pre class="mono">${esc(cmd)}</pre>
                <button class="btn sm" data-exec="${encodeURIComponent(cmd)}">${icon('arrowUR')}${t('ai.run')}</button>
                <div class="ai-cmd-out" hidden></div></div>`;
        } else {
            html += `<pre class="mono ai-code">${esc(cmd)}</pre>`;
        }
    }
    return html || esc(text);
}

// AI operacije su async taskovi — poll task.output iz baze (ne blokira panel)
async function pollAiTask(id, { interval = 1500, maxMs = 200000 } = {}) {
    const start = Date.now();
    for (;;) {
        const r = await api(`/assistant/task/${id}`);
        if (['done', 'failed', 'cancelled'].includes(r.status)) return r;
        if (Date.now() - start > maxMs) return { status: 'failed', error: 'timeout' };
        await new Promise((res) => setTimeout(res, interval));
    }
}

// Kao pollAiTask, ali zove onPartial(output) dok task traje (live ispis)
async function pollAiTaskLive(id, onPartial, { interval = 800, maxMs = 200000 } = {}) {
    const start = Date.now();
    let last = '';
    for (;;) {
        const r = await api(`/assistant/task/${id}`);
        if (['done', 'failed', 'cancelled'].includes(r.status)) return r;
        if (r.output && r.output !== last) { last = r.output; onPartial(r.output); }
        if (Date.now() - start > maxMs) return { status: 'failed', error: 'timeout' };
        await new Promise((res) => setTimeout(res, interval));
    }
}

async function renderAiDrawer() {
    const drawer = document.getElementById('aidrawer');
    if (!drawer) return;
    drawer.hidden = false;
    document.getElementById('aibtn')?.classList.add('active');
    drawer.innerHTML = `
        <header>
            <span class="mark">${icon('sparkle')}</span>
            <div><div class="title">Forge AI</div><div class="sub">${t('ai.sub')}</div></div>
            <button class="icon-btn" id="aiclose" style="margin-left:auto" aria-label="${t('common.cancel')}">${icon('x')}</button>
        </header>
        <div class="ai-thread" id="aithread"><div class="empty">${t('common.loading')}</div></div>
        <div class="ai-foot" id="aifoot"></div>`;
    drawer.querySelector('#aiclose').addEventListener('click', () => toggleAiDrawer(false));

    let status;
    try { status = await api('/assistant/status'); }
    catch (err) { drawer.querySelector('#aithread').innerHTML = `<div class="alert err">${esc(err.message)}</div>`; return; }
    state.aiMode = status.mode || null;

    if (!status.configured) {
        drawer.querySelector('#aithread').innerHTML = `
            <div class="ai-msg-ai">${t('assistant.intro')}</div>
            <form id="aikf">
                <div class="field"><label>${t('assistant.api_key')}</label>
                    <input name="api_key" type="password" required class="mono" placeholder="sk-ant-..."></div>
                <div class="field"><label>${t('assistant.model')}</label>
                    <select name="model" class="mono">
                        <option value="claude-opus-4-8">Claude Opus 4.8 (preporučeno)</option>
                        <option value="claude-sonnet-4-6">Claude Sonnet 4.6 (brže/jeftinije)</option>
                        <option value="claude-haiku-4-5">Claude Haiku 4.5 (najjeftinije)</option>
                        <option value="claude-fable-5">Claude Fable 5 (najmoćnije)</option>
                    </select></div>
                <button class="btn primary">${t('assistant.connect')}</button>
            </form>`;
        drawer.querySelector('#aikf').addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                await api('/assistant/key', { method: 'POST', body: Object.fromEntries(new FormData(e.target)) });
                toast(t('assistant.connected'));
                renderAiDrawer();
            } catch (err) { toast(err.message, 'err'); }
        });
        return;
    }

    const canExec = state.aiMode === 'local';
    const threadEl = drawer.querySelector('#aithread');
    const renderThread = () => {
        threadEl.innerHTML = state.aiThread.length
            ? state.aiThread.map((m) => m.who === 'user'
                ? `<div class="ai-msg-user">${esc(m.text)}</div>`
                // exec gumbi tek kad je odgovor gotov (ne na djelomičnom streamu)
                : `<div class="ai-msg-ai${m.pending ? ' pending' : ''}">${aiMessageHtml(m.text, canExec && !m.pending)}</div>`).join('')
            : `<div class="ai-msg-ai">${t('ai.welcome')}${canExec ? ' <span class="badge ok">lokalni Claude</span>' : ''}</div>`;
        threadEl.scrollTop = threadEl.scrollHeight;
        // perzistiraj povijest (bez in-flight placeholdera) — preživi refresh
        try { localStorage.setItem('fp_ai_thread', JSON.stringify(state.aiThread.filter((m) => !m.pending).slice(-40))); } catch {}
    };
    renderThread();

    // Izvrši (samo lokalni mod): pokreni potvrđenu komandu preko agenta
    threadEl.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-exec]');
        if (!btn) return;
        const cmd = decodeURIComponent(btn.dataset.exec);
        const out = btn.parentElement.querySelector('.ai-cmd-out');
        btn.disabled = true;
        out.hidden = false;
        out.textContent = '…';
        try {
            const r = await api('/assistant/exec', { method: 'POST', body: { command: cmd } });
            const res = await pollAiTask(r.task_id);
            out.textContent = res.status === 'done' ? (res.output || '') : ('✕ ' + (res.error || 'neuspješno'));
            out.classList.toggle('err', res.status !== 'done' || /\[exit [1-9]/.test(res.output || ''));
        } catch (err) { out.textContent = '✕ ' + err.message; out.classList.add('err'); }
        finally { btn.disabled = false; }
    });

    drawer.querySelector('#aifoot').innerHTML = `
        <div class="ai-sugg">${[t('ai.sugg1'), t('ai.sugg2'), t('ai.sugg3')].map((s) =>
            `<button type="button" data-sugg="${esc(s)}">${esc(s)}</button>`).join('')}
            <button type="button" data-clear>${t('ai.clear')}</button>
            ${status.mode === 'api' ? `<button type="button" data-disconnect title="${esc(status.model)}">⚙ ${t('assistant.disconnect')}</button>` : ''}</div>
        <form class="ai-inputrow" id="aiform">
            <input name="q" placeholder="${t('assistant.placeholder')}" autocomplete="off">
            <button class="btn primary icon" aria-label="${t('assistant.send')}">${icon('arrowUR')}</button>
        </form>`;

    const input = drawer.querySelector('#aiform input');
    const ask = async (question) => {
        if (!question) return;
        state.aiThread.push({ who: 'user', text: question });
        const idx = state.aiThread.push({ who: 'ai', text: t('assistant.thinking'), pending: true }) - 1;
        renderThread();
        try {
            const r = await api('/assistant/ask', { method: 'POST', body: { question, context: '' } });
            if (r.task_id) {
                // live: prikazuj djelomičan odgovor kako stiže
                const res = await pollAiTaskLive(r.task_id, (partial) => {
                    if (partial) { state.aiThread[idx] = { who: 'ai', text: partial, pending: true }; renderThread(); }
                });
                state.aiThread[idx] = { who: 'ai', text: res.status === 'done' ? (res.output || '') : ('✕ ' + (res.error || 'neuspješno')) };
            } else {
                state.aiThread[idx] = { who: 'ai', text: r.answer }; // API mod (sinkrono)
            }
        } catch (err) {
            state.aiThread[idx] = { who: 'ai', text: '✕ ' + err.message };
        }
        renderThread();
    };
    state.aiAsk = ask; // omogući "Otvori analizu" s dashboarda da postavi upit
    if (state.aiPending) { const q = state.aiPending; state.aiPending = null; ask(q); }
    drawer.querySelector('#aiform').addEventListener('submit', (e) => {
        e.preventDefault();
        const q = input.value.trim();
        input.value = '';
        ask(q);
    });
    drawer.querySelectorAll('[data-sugg]').forEach((b) => b.addEventListener('click', () => ask(b.dataset.sugg)));
    drawer.querySelector('[data-clear]')?.addEventListener('click', () => {
        state.aiThread = [];
        localStorage.removeItem('fp_ai_thread');
        renderThread();
    });
    drawer.querySelector('[data-disconnect]')?.addEventListener('click', async () => {
        if (!confirm(t('assistant.confirm_disconnect'))) return;
        try { await api('/assistant/key', { method: 'DELETE' }); state.aiThread = []; localStorage.removeItem('fp_ai_thread'); renderAiDrawer(); }
        catch (err) { toast(err.message, 'err'); }
    });
    input.focus();
}

// ---------------------------------------------------------------- stranice
// mini sparkline za metric kartice
function spark(values, { w = 84, h = 30, color = 'var(--accent)' } = {}) {
    if (!values || values.length < 2) return '';
    const max = Math.max(...values) * 1.1 || 1;
    const step = w / (values.length - 1);
    const pts = values.map((v, i) => `${(i * step).toFixed(1)},${(h - 2 - (v / max) * (h - 4)).toFixed(1)}`);
    const last = pts[pts.length - 1].split(',');
    return `<svg width="${w}" height="${h}" style="overflow:visible;flex-shrink:0">
        <polygon points="0,${h} ${pts.join(' ')} ${w},${h}" fill="${color}" opacity="0.12"/>
        <polyline points="${pts.join(' ')}" fill="none" stroke="${color}" stroke-width="1.6" stroke-linejoin="round"/>
        <circle cx="${last[0]}" cy="${last[1]}" r="2.4" fill="${color}"/></svg>`;
}

const metricCard = ({ label, value, unit = '', sub = '', sparkHtml = '' }) => `
    <div class="card metric">
        <div class="label">${label}</div>
        <div class="row"><div>
            <span class="value num">${value}</span>${unit ? `<span class="unit num">${unit}</span>` : ''}
            <div class="sub num">${sub}</div>
        </div>${sparkHtml}</div>
    </div>`;

// uptime servisa iz ActiveEnterTimestamp ("Day YYYY-MM-DD HH:MM:SS TZ")
function svcUptime(props) {
    const ts = props.ActiveEnterTimestamp;
    if (!ts || ts === '0' || props.ActiveState !== 'active') return '—';
    const m = String(ts).match(/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/);
    if (!m) return '—';
    const sec = (Date.now() - new Date(m[1].replace(' ', 'T'))) / 1000;
    if (sec < 3600) return `${Math.floor(sec / 60)}m`;
    if (sec < 86400) return `${Math.floor(sec / 3600)}h`;
    return `${Math.floor(sec / 86400)}d`;
}

const SEV = { ok: 'var(--ok)', err: 'var(--danger)', warn: 'var(--warn)', info: 'var(--info)' };

async function refreshDashboard(cfConnected) {
    const body = document.getElementById('dashbody');
    if (!body) return;
    const [vhosts, metrics, services, feed, insights] = await Promise.all([
        api('/vhosts').catch(() => []),
        api('/monitoring/now').catch(() => null),
        api('/monitoring/services').catch(() => ({})),
        api('/dashboard/feed').catch(() => ({ events: [], deploys: [] })),
        api('/dashboard/insights').catch(() => ({ items: [] })),
    ]);
    const [cpuHist, memHist, netRx] = await Promise.all([
        api('/monitoring/history?metric=cpu_pct&range=1h').catch(() => []),
        api('/monitoring/history?metric=mem_used_bytes&range=1h').catch(() => []),
        api('/monitoring/history?metric=net_rx_bps&range=1h').catch(() => []),
    ]);

    const upCount = vhosts.filter((v) => v.status === 'active').length;
    const sv = Object.entries(services);
    const healthy = sv.filter(([, p]) => p.ActiveState === 'active').length;

    // KPI kartice (pravi podaci)
    const kpis = [];
    if (metrics) {
        const cpuPct = metrics.cpu_pct ?? Math.min(100, metrics.load[0] / metrics.cpu_count * 100);
        kpis.push(metricCard({ label: 'CPU', value: Math.round(cpuPct), unit: '%',
            sub: `load ${metrics.load.map((l) => l.toFixed(2)).join(' · ')}`,
            sparkHtml: spark(cpuHist.slice(-44).map((p) => Number(p.value))) }));
        kpis.push(metricCard({ label: 'RAM', value: fmtBytes(metrics.mem_total_bytes - metrics.mem_available_bytes).replace(/ .*/, ''),
            unit: `/ ${fmtBytes(metrics.mem_total_bytes)}`, sub: `${Math.round((1 - metrics.mem_available_bytes / metrics.mem_total_bytes) * 100)}% iskorišteno`,
            sparkHtml: spark(memHist.slice(-44).map((p) => Number(p.value)), { color: 'var(--info)' }) }));
        kpis.push(metricCard({ label: 'Disk', value: fmtBytes(metrics.disk_total_bytes - metrics.disk_free_bytes).replace(/ .*/, ''),
            unit: `/ ${fmtBytes(metrics.disk_total_bytes)}`, sub: `${Math.round((1 - metrics.disk_free_bytes / metrics.disk_total_bytes) * 100)}% · ${fmtBytes(metrics.disk_free_bytes)} slobodno` }));
        kpis.push(metricCard({ label: 'Mreža', value: lastVal(netRx) != null ? fmtBytes(lastVal(netRx)).replace(/ .*/, '') : '—',
            unit: lastVal(netRx) != null ? fmtBytes(lastVal(netRx)).replace(/^[\d.,]+ /, '') + '/s' : '', sub: 'dolazni promet',
            sparkHtml: spark(netRx.slice(-44).map((p) => Number(p.value)), { color: 'var(--ok)' }) }));
    }
    kpis.push(metricCard({ label: t('nav.websites'), value: vhosts.length, sub: `${upCount} aktivnih` }));

    body.innerHTML = `
    <div class="grid cols-5" style="margin-bottom:var(--gap)">${kpis.slice(0, 5).join('')}</div>

    ${insights.items.length ? `
    <div class="ai-box dash-ai" style="margin-bottom:var(--gap)">
        <span class="mark">${icon('sparkle')}</span>
        <div style="min-width:0;flex:1">
            <div style="font-weight:650;margin-bottom:5px">Forge AI · ${insights.items.length} ${insights.items.length === 1 ? 'preporuka' : 'preporuke'}</div>
            ${insights.items.map((it) => `<div class="dash-ai-row">
                ${dot(it.severity)}<span>${esc(it.text)}</span></div>`).join('')}
            <div style="margin-top:10px;display:flex;gap:8px">
                ${insights.items.find((i) => i.ai_prompt) ? `<button class="btn small primary" id="dashai">${icon('sparkle')}${t('dash.open_analysis')}</button>` : ''}
            </div>
        </div>
    </div>` : ''}

    <div class="grid split">
        <div style="display:flex;flex-direction:column;gap:var(--gap);min-width:0">
            <div class="card flush">
                <div class="card-head"><h2>${t('dash.topology')}</h2><span class="spacer"></span>
                    <span class="badge ${healthy === sv.length ? 'ok' : 'warn'}">${healthy}/${sv.length} ${t('dash.healthy')}</span></div>
                <div class="topo">
                    <span class="topo-node">${dot('ok')} Internet</span>
                    ${cfConnected ? `<span class="topo-link"></span><span class="topo-node">${icon('cloud')} Cloudflare</span>` : ''}
                    <span class="topo-link"></span><span class="topo-node">${dot(serviceUp(services, 'nginx') ? 'ok' : 'err')} nginx</span>
                    <span class="topo-link"></span><span class="topo-stack">
                        ${sv.filter(([n]) => /fpm|apache2/.test(n)).map(([n, p]) => `<span class="topo-node sm">${dot(p.ActiveState === 'active' ? 'ok' : 'err')} ${esc(n.replace('-fpm', ''))}</span>`).join('') || `<span class="topo-node sm">${dot('warn')} php-fpm</span>`}
                    </span>
                    <span class="topo-link"></span><span class="topo-stack">
                        ${sv.filter(([n]) => /maria|mysql|redis/.test(n)).map(([n, p]) => `<span class="topo-node sm">${dot(p.ActiveState === 'active' ? 'ok' : 'err')} ${esc(n)}</span>`).join('') || `<span class="topo-node sm">${dot('warn')} db</span>`}
                    </span>
                </div>
                <div class="topo-foot mono">uptime ${metrics ? Math.floor(metrics.uptime_s / 86400) + 'd ' + Math.floor((metrics.uptime_s % 86400) / 3600) + 'h' : '—'} · Ubuntu 26.04 LTS</div>
            </div>
            <div class="card flush">
                <div class="card-head"><h2>${t('dash.services')}</h2><span class="spacer"></span>
                    <a class="btn small" href="#/monitoring">${t('dash.all')} ${icon('chevR')}</a></div>
                <table class="data"><thead><tr><th>${t('mon.service')}</th><th>${t('mon.state')}</th><th class="num">CPU</th><th class="num">RAM</th><th class="num hide-sm">Uptime</th></tr></thead><tbody>
                ${sv.map(([name, p]) => `<tr>
                    <td><span style="display:flex;align-items:center;gap:8px;font-weight:550">${dot(p.ActiveState === 'active' ? 'ok' : p.ActiveState === 'failed' ? 'err' : 'warn')}<span class="mono">${esc(name)}</span></span></td>
                    <td><span class="badge ${p.ActiveState === 'active' ? 'ok' : p.ActiveState === 'failed' ? 'err' : ''}">${esc(p.SubState || p.ActiveState || '?')}</span></td>
                    <td class="mono num">${p.cpu_pct != null ? p.cpu_pct.toFixed(1) + '%' : '—'}</td>
                    <td class="mono num">${p.mem_bytes != null ? fmtBytes(p.mem_bytes) : '—'}</td>
                    <td class="mono num hide-sm">${svcUptime(p)}</td></tr>`).join('')}
                </tbody></table>
            </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:var(--gap);min-width:0">
            <div class="card flush">
                <div class="card-head"><h2>${t('dash.live_events')}</h2><span class="spacer"></span>
                    <span class="live-dot">${dot('ok', true)} live</span></div>
                <div class="feed">${feed.events.length ? feed.events.map((e) => `
                    <div class="feed-row">
                        <span class="feed-time mono">${fmtTime(e.ts)}</span>
                        <span class="feed-ico" style="color:${SEV[e.severity] || 'var(--ink-3)'}">${icon(feedIcon(e.kind, e.severity))}</span>
                        <span class="feed-text">${esc(e.text)}</span>
                    </div>`).join('') : `<div class="empty">Nema događaja u zadnja 24 h</div>`}</div>
            </div>
            <div class="card flush">
                <div class="card-head"><h2>${t('dash.recent_deploys')}</h2></div>
                ${feed.deploys.length ? feed.deploys.map((d) => `
                    <div class="feed-row">
                        <span class="feed-ico" style="color:var(--ok)">${icon('check')}</span>
                        <span class="mono" style="min-width:0;overflow:hidden;text-overflow:ellipsis">${esc(d.domain)}</span>
                        <span class="mono hide-sm" style="color:var(--ink-3);font-size:var(--fs-xs)">${esc(d.branch || '')} ${d.last_commit ? esc(String(d.last_commit).slice(0, 7)) : ''}</span>
                        <span style="margin-left:auto;color:var(--ink-3);font-size:var(--fs-xs);white-space:nowrap">${timeAgo(d.last_deploy_at)}</span>
                    </div>`).join('') : `<div class="empty">Nema deploya</div>`}
            </div>
        </div>
    </div>`;

    const aibtn = document.getElementById('dashai');
    if (aibtn) aibtn.addEventListener('click', () => {
        state.aiPending = insights.items.find((i) => i.ai_prompt)?.ai_prompt || null;
        toggleAiDrawer(true); // renderAiDrawer pokupi state.aiPending kad se učita
    });
}

const serviceUp = (services, name) => Object.entries(services).some(([n, p]) => n.startsWith(name) && p.ActiveState === 'active');
const feedIcon = (kind, sev) => sev === 'err' ? 'x' : sev === 'ok' ? 'check' : kind === 'uptime' ? 'globe' : kind === 'audit' ? 'shield' : 'activity';

async function pageDashboard() {
    setActive('dashboard');
    const isAdmin = state.me.role === 'admin';
    if (!isAdmin) { return pageDashboardClient(); }
    main().innerHTML = `<div id="dashbody"><div class="empty">${t('common.loading')}</div></div>`;
    const cf = await api('/cloudflare/account').catch(() => ({ connected: false }));
    await refreshDashboard(cf.connected);
    // live auto-refresh feeda + KPI svakih 10 s (čisti se u route() pri navigaciji)
    state.monTimer = setInterval(() => refreshDashboard(cf.connected), 10000);
    bindVhostRows();
}

// klijentski dashboard (bez admin metrika) — zadrži jednostavan prikaz
async function pageDashboardClient() {
    main().innerHTML = `<div class="empty">${t('common.loading')}</div>`;
    const [vhosts, certs] = await Promise.all([api('/vhosts').catch(() => []), api('/ssl').catch(() => [])]);
    const upCount = vhosts.filter((v) => v.status === 'active').length;
    main().innerHTML = `
    <div class="grid cols-4" style="margin-bottom:var(--gap)">
        ${metricCard({ label: t('nav.websites'), value: vhosts.length, sub: `${upCount} aktivnih` })}
        ${metricCard({ label: t('nav.ssl'), value: certs.length })}
    </div>
    <div class="card flush">
        <div class="card-head"><h2>${t('nav.websites')}</h2></div>
        ${vhostTable(vhosts.slice(0, 12))}
    </div>`;
    bindVhostRows();
}

const statusBadge = (s) => {
    const kind = { active: 'ok', done: 'ok', creating: 'info', running: 'info', pending: 'info', suspended: 'warn' }[s] ?? 'err';
    return `<span class="badge ${kind}">${t('vhost.status.' + s) !== 'vhost.status.' + s ? t('vhost.status.' + s) : esc(s)}</span>`;
};

const sslBadge = (days) => days == null ? '<span class="mono" style="color:var(--ink-3)">—</span>'
    : `<span class="badge ${days < 7 ? 'err' : days < 21 ? 'warn' : 'ok'}">${days}d</span>`;

const vhostTable = (vhosts, selectable = false) => vhosts.length ? `
    <table class="data"><thead><tr>
        ${selectable ? '<th><input type="checkbox" id="selall"></th>' : ''}
        <th>${t('vhost.domain')}</th><th class="hide-sm">Stack</th>
        ${selectable ? '' : '<th>SSL</th><th class="hide-sm">Deploy</th>'}
        <th>Status</th>
    </tr></thead><tbody>
    ${vhosts.map((v) => `
        <tr class="${selectable ? '' : 'row-link'}" data-vhost="${v.id}">
            ${selectable ? `<td><input type="checkbox" class="vsel" value="${v.id}"></td>` : ''}
            <td><span class="mono" style="font-weight:600">${esc(v.domain)}</span></td>
            <td class="mono hide-sm" style="color:var(--ink-2)">PHP ${esc(v.php_version)} · ${esc(v.web_backend === 'nginx_apache' ? 'apache' : 'nginx')}</td>
            ${selectable ? '' : `<td>${sslBadge(v.ssl_days)}</td>
            <td class="mono hide-sm" style="color:var(--ink-2)">${v.git_branch ? esc(v.git_branch) + ' · ' + timeAgo(v.git_last_deploy) : '—'}</td>`}
            <td>${statusBadge(v.status)}</td>
        </tr>`).join('')}
    </tbody></table>` : `<div class="empty">${t('nav.websites')}: 0</div>`;

const bindVhostRows = () => main().querySelectorAll('[data-vhost]').forEach((tr) =>
    tr.addEventListener('click', () => { location.hash = `#/websites/${tr.dataset.vhost}`; }));

// ---- Siteovi: master-detail (lista + detalj panel) ----
const dotKind = (v) => v.status === 'active' ? 'ok' : v.status === 'error' ? 'err' : v.status === 'suspended' ? 'warn' : 'info';
const appLabel = (a) => ({ wordpress: 'WordPress', woocommerce: 'WooCommerce', laravel: 'Laravel', node: 'Node.js', nextjs: 'Next.js', astro: 'Astro', static: 'Static', php: 'PHP' }[a] || '');
const stackText = (v) => `PHP ${esc(v.php_version)} · ${v.web_backend === 'nginx_apache' ? 'apache' : 'nginx'}`;

const sitesList = (vhosts, selectedId) => vhosts.length ? `
    <table class="data sites-table"><thead><tr>
        <th>${t('sites.col_site')}</th>
        <th class="hide-sm">${t('sites.col_stack')}</th>
        <th class="hide-md num">${t('sites.col_traffic')}</th>
        <th>SSL</th>
        <th class="hide-md">${t('sites.col_deploy')}</th>
        <th class="num">${t('sites.col_disk')}</th>
    </tr></thead><tbody>
    ${vhosts.map((v) => `
        <tr class="row-link ${v.id === selectedId ? 'selected' : ''}" data-vhost="${v.id}">
            <td><div class="site-cell"><span class="status-dot ${dotKind(v)}"></span>
                <div><span class="mono site-name">${esc(v.domain)}</span>
                ${appLabel(v.app_type) ? `<div class="sub">${appLabel(v.app_type)}</div>` : ''}</div></div></td>
            <td class="mono hide-sm sub2">${stackText(v)}</td>
            <td class="mono hide-md num sub2">—</td>
            <td>${sslBadge(v.ssl_days)}</td>
            <td class="mono hide-md sub2">${v.git_branch ? esc(v.git_branch) + ' · ' + timeAgo(v.git_last_deploy) : '—'}</td>
            <td class="num mono sub2">${v.disk_bytes != null ? fmtBytes(Number(v.disk_bytes)) : '—'}</td>
        </tr>`).join('')}
    </tbody></table>` : `<div class="empty">${t('nav.websites')}: 0</div>`;

const sitePanel = (v) => !v ? `<div class="empty">${t('sites.select')}</div>` : `
    <div class="sp-head">
        <div class="sp-titles"><div class="sp-title mono">${esc(v.domain)}</div>
            <div class="sp-sub">${stackText(v)}${appLabel(v.app_type) ? ' · ' + appLabel(v.app_type) : ''}</div></div>
        <a class="btn ghost icon" href="#/websites/${v.id}" title="${t('sites.open')}">${icon('arrowUR')}</a>
    </div>
    <div class="sp-spark"><div class="spark-line"></div><div class="sp-spark-cap">${t('sites.visits_24h')} · —</div></div>
    <div class="sp-stats">
        <div class="sp-stat"><span class="k">${t('sites.col_traffic')}</span><span class="vv mono">—</span></div>
        <div class="sp-stat"><span class="k">Trend</span><span class="vv mono">—</span></div>
        <div class="sp-stat"><span class="k">PHP</span><span class="vv mono">${esc(v.php_version)}</span></div>
        <div class="sp-stat"><span class="k">${t('sites.col_disk')}</span><span class="vv mono">${v.disk_bytes != null ? fmtBytes(Number(v.disk_bytes)) : '—'}</span></div>
    </div>
    <div class="sp-actions">
        <a class="btn primary sm" href="#/websites/${v.id}">${icon('zap')}Deploy</a>
        <a class="btn sm" href="#/websites/${v.id}">${icon('folder')}${t('nav.files')}</a>
        ${state.me.role === 'admin' ? `<a class="btn sm" href="#/websites/${v.id}">${icon('terminal')}SSH</a>` : ''}
    </div>
    <div class="sp-card">
        <div class="sp-card-h">${t('sites.domains_ssl')}</div>
        <div class="sp-domain"><span class="mono">${esc(v.domain)}</span><span class="badge ok">${t('sites.active')}</span></div>
        <div class="sp-domain"><span class="mono">www.${esc(v.domain)}</span><span class="badge ok">${t('sites.active')}</span></div>
    </div>
    <div class="sp-card">
        <div class="sp-card-h">${t('sites.quick_actions')}</div>
        <button class="sp-qa" data-qa="backup">${icon('archive')}<span>${t('sites.qa_backup')}</span>${icon('chevR', 14)}</button>
        <button class="sp-qa" data-qa="ssl">${icon('refresh')}<span>${t('sites.qa_ssl')}</span>${icon('chevR', 14)}</button>
        <a class="sp-qa" href="#/websites/${v.id}">${icon('gear')}<span>${t('sites.qa_settings')}</span>${icon('chevR', 14)}</a>
    </div>`;

async function pageWebsites() {
    setActive('websites');
    main().innerHTML = `<div class="empty">${t('common.loading')}</div>`;
    const vhosts = await api('/vhosts');
    const domainCount = vhosts.reduce((n, v) => n + 1 + (Number(v.alias_count) || 0), 0);
    const isProblem = (v) => v.status === 'error' || v.status === 'suspended' || (v.ssl_days != null && v.ssl_days < 7);
    let filter = 'all';
    let bulkMode = false;
    let selectedId = (vhosts.find((v) => v.status === 'active') ?? vhosts[0])?.id ?? null;

    main().innerHTML = `
    <div class="page-head sites-head">
        <div class="tabs" id="filters">
            <button class="tab active" data-f="all">${t('sites.all')}</button>
            <button class="tab" data-f="live">${t('sites.live')}</button>
            <button class="tab" data-f="problems">${t('sites.problems')}<span class="tab-count" id="pc"></span></button>
        </div>
        <div class="spacer"></div>
        <span class="sites-count mono">${vhosts.length} ${t('sites.sites')} · ${domainCount} ${t('sites.domains')}</span>
        <button class="btn" id="bulkbtn">${t('bulk.title')}</button>
        <button class="btn primary" id="new">${icon('plus')}${t('vhost.create')}</button>
    </div>
    <div id="bulkbar" hidden class="card mb"></div>
    <div class="sites-layout" id="layout">
        <div class="card sites-list" id="list"></div>
        <aside class="card site-panel" id="panel"></aside>
    </div>`;

    const probCount = vhosts.filter(isProblem).length;
    const pc = document.getElementById('pc');
    if (probCount) pc.textContent = probCount; else pc.remove();
    document.getElementById('new').addEventListener('click', createVhostModal);

    const filtered = () => vhosts.filter((v) => filter === 'all' ? true : filter === 'live' ? v.status === 'active' : isProblem(v));

    const renderPanel = () => {
        const v = vhosts.find((x) => x.id === selectedId);
        const panel = document.getElementById('panel');
        panel.innerHTML = sitePanel(v);
        if (!v) return;
        panel.querySelector('[data-qa="backup"]')?.addEventListener('click', async () => {
            try { const r = await api(`/vhosts/${v.id}/backups`, { method: 'POST', body: {} }); watchTask(r.task_id, `backup ${v.domain}`); }
            catch (err) { toast(err.message, 'err'); }
        });
        panel.querySelector('[data-qa="ssl"]')?.addEventListener('click', async () => {
            try { const r = await api(`/vhosts/${v.id}/ssl/renew`, { method: 'POST' }); watchTask(r.task_id, `ssl.issue ${v.domain}`); }
            catch (err) { toast(err.message, 'err'); }
        });
    };

    const renderList = () => {
        const list = document.getElementById('list');
        const rows = filtered();
        if (bulkMode) {
            list.innerHTML = vhostTable(rows, true);
            list.querySelector('#selall')?.addEventListener('change', (e) =>
                list.querySelectorAll('.vsel').forEach((c) => { c.checked = e.target.checked; }));
            return;
        }
        list.innerHTML = sitesList(rows, selectedId);
        list.querySelectorAll('[data-vhost]').forEach((tr) => tr.addEventListener('click', () => {
            selectedId = Number(tr.dataset.vhost);
            list.querySelectorAll('.row-link').forEach((r) => r.classList.toggle('selected', Number(r.dataset.vhost) === selectedId));
            renderPanel();
        }));
    };

    renderList();
    renderPanel();

    document.querySelectorAll('#filters .tab').forEach((b) => b.addEventListener('click', () => {
        filter = b.dataset.f;
        document.querySelectorAll('#filters .tab').forEach((x) => x.classList.toggle('active', x === b));
        if (!filtered().some((v) => v.id === selectedId)) selectedId = filtered()[0]?.id ?? null;
        renderList();
        renderPanel();
    }));

    document.getElementById('bulkbtn').addEventListener('click', () => {
        bulkMode = !bulkMode;
        document.getElementById('layout').classList.toggle('bulk', bulkMode);
        document.getElementById('panel').hidden = bulkMode;
        const bar = document.getElementById('bulkbar');
        bar.hidden = !bulkMode;
        if (bulkMode) {
            bar.innerHTML = `
                <div class="grid cols-4" style="align-items:end">
                    <div class="field"><label>${t('bulk.action')}</label>
                        <select id="ba" class="mono">
                            <option value="php_set">${t('bulk.php_set')}</option>
                            <option value="ssl_renew">${t('bulk.ssl_renew')}</option>
                            <option value="malware_scan">${t('bulk.malware_scan')}</option>
                            <option value="backup">${t('bulk.backup')}</option>
                            <option value="suspend">${t('bulk.suspend')}</option>
                            <option value="unsuspend">${t('bulk.unsuspend')}</option>
                        </select></div>
                    <div class="field" id="phpwrap"><label>PHP</label>
                        <select id="bphp" class="mono">${['8.5', '8.4', '8.3', '8.2', '8.1'].map((v) => `<option>${v}</option>`).join('')}</select></div>
                    <div class="field"><label>&nbsp;</label><button class="btn primary" id="barun">${t('bulk.run')}</button></div>
                </div>`;
            bar.querySelector('#ba').addEventListener('change', (e) => {
                bar.querySelector('#phpwrap').style.display = e.target.value === 'php_set' ? '' : 'none';
            });
            bar.querySelector('#barun').addEventListener('click', async () => {
                const sel = [...main().querySelectorAll('.vsel:checked')].map((c) => Number(c.value));
                if (sel.length === 0) return toast(t('bulk.none_selected'), 'err');
                const action = bar.querySelector('#ba').value;
                try {
                    const r = await api('/bulk/vhosts', { method: 'POST', body: { action, vhost_ids: sel, php_version: bar.querySelector('#bphp').value } });
                    const ok = Object.values(r.results).filter((x) => !x.error).length;
                    toast(`${t('bulk.done')}: ${ok}/${sel.length}`);
                    Object.values(r.results).forEach((x) => x.task_id && watchTask(x.task_id, `bulk ${action} ${x.domain}`));
                    pageWebsites();
                } catch (err) { toast(err.message, 'err'); }
            });
        }
        renderList();
    });
}

async function createVhostModal() {
    const isPriv = state.me.role === 'admin' || state.me.role === 'reseller';
    let subs = [];
    if (isPriv) { try { subs = await api('/subscriptions'); } catch { /* fallback: auto-pretplata */ } }
    const subField = isPriv && subs.length ? `
            <div class="field"><label>${t('vhost.subscription')}</label>
                <select name="subscription_id" class="mono">
                    ${subs.map((s) => `<option value="${s.id}">${esc(s.email)} — ${esc(s.plan)}</option>`).join('')}
                </select></div>` : '';
    const modal = openModal(`
        <div class="dialog-head"><h1>${t('vhost.create')}</h1><button class="btn ghost icon" data-close>${icon('x')}</button></div>
        <form id="vf">
            <div class="field"><label>${t('vhost.domain')}</label>
                <input name="domain" required placeholder="example.com" class="mono" autocomplete="off">
                <span class="hint">Bez www — alias se dodaje automatski (AutoSSL pokriva oba).</span></div>
            ${subField}
            <div class="field"><label>${t('vhost.php_version')}</label>
                <select name="php_version">${['8.5', '8.4', '8.3', '8.2', '8.1'].map((v) => `<option>${v}</option>`).join('')}</select></div>
            <div class="dialog-foot">
                <button type="button" class="btn" data-close>${t('common.cancel')}</button>
                <button class="btn primary">${t('common.create')}</button>
            </div>
        </form>`);
    modal.querySelector('#vf').addEventListener('submit', async (e) => {
        e.preventDefault();
        const body = Object.fromEntries(new FormData(e.target));
        try {
            const r = await api('/vhosts', { method: 'POST', body });
            modal.close();
            watchTask(r.task_id, `vhost.create ${body.domain}`);
            watchTask(r.ssl_task_id, `ssl.issue ${body.domain}`);
            location.hash = '#/websites';
            route();
        } catch (err) { toast(err.message, 'err'); }
    });
}

async function pageWebsiteDetail(id) {
    setActive('websites');
    main().innerHTML = `<div class="empty">${t('common.loading')}</div>`;
    const vhost = await api(`/vhosts/${id}`);
    setActive('websites', [t('nav.websites'), vhost.domain]);

    const uptime = vhost.uptime;
    const uptimeBadge = uptime
        ? `<span class="badge ${uptime.last_status === 'up' ? 'ok' : uptime.last_status === 'down' ? 'err' : ''}">
            ${uptime.last_status}${uptime.response_ms ? ` · ${uptime.response_ms} ms` : ''}</span>`
        : '';

    main().innerHTML = `
    <div class="page-head">
        <h1 class="mono">${esc(vhost.domain)}</h1>${statusBadge(vhost.status)}${uptimeBadge}
        <div class="spacer"></div>
        <button class="btn" id="bkp">${icon('archive')}${t('backup.create')}</button>
        ${state.me.role === 'admin' ? `<button class="btn" id="diag">${icon('spark')}${t('assistant.diagnose')}</button>` : ''}
        <button class="btn" id="renew">${icon('refresh')}SSL renew</button>
        <button class="btn danger" id="del">${t('common.delete')}</button>
    </div>
    <div id="diagbox"></div>
    <div class="grid cols-2">
        <div class="card">
            <h2>Postavke</h2>
            <table class="data"><tbody>
                <tr><td>${t('vhost.php_version')}</td><td>
                    <select id="php" class="mono">${['8.1', '8.2', '8.3', '8.4', '8.5'].map((v) =>
                        `<option ${v === vhost.php_version ? 'selected' : ''}>${v}</option>`).join('')}</select></td></tr>
                <tr><td>Backend</td><td>
                    <select id="backend" class="mono">
                        <option value="nginx" ${vhost.web_backend === 'nginx' ? 'selected' : ''}>nginx + FPM (brže)</option>
                        <option value="nginx_apache" ${vhost.web_backend === 'nginx_apache' ? 'selected' : ''}>nginx → Apache (.htaccess)</option>
                    </select></td></tr>
                <tr><td>Sistemski user</td><td class="mono">${esc(vhost.sys_user)}</td></tr>
                <tr><td>Docroot</td><td class="mono">${esc(vhost.docroot)}</td></tr>
                <tr><td>Kreirano</td><td>${fmtDate(vhost.created_at)}</td></tr>
            </tbody></table>
        </div>
        <div class="card">
            <div class="page-head"><h2>${t('nav.files')}</h2></div>
            <div id="fm"></div>
        </div>
    </div>
    <div class="grid cols-2 mt">
        <div class="card">
            <div class="page-head"><h2>${t('cron.title')}</h2></div>
            <div id="cron"></div>
        </div>
        <div class="card">
            <div class="page-head"><h2>${t('ftp.title')}</h2></div>
            <div id="ftp"></div>
        </div>
    </div>
    <div class="card mt">
        <div class="page-head"><h2>${t('git.title')}</h2></div>
        <div id="git"></div>
    </div>
    <div class="card mt">
        <div class="page-head"><h2>${t('staging.title')}</h2><div class="spacer"></div>
            <button class="btn" id="newstg">${icon('plus')}${t('staging.create')}</button></div>
        <div id="staging"></div>
    </div>
    <div class="card mt"><div class="page-head"><h2>${t('apps.title')}</h2></div><div id="apps"></div></div>
    ${state.me.role === 'admin' ? `<div class="card mt"><div class="page-head"><h2>${t('term.title')}</h2></div><div id="term"></div></div>` : ''}`;

    main().querySelector('#php').addEventListener('change', async (e) => {
        try {
            await api(`/vhosts/${id}/php`, { method: 'PUT', body: { php_version: e.target.value } });
            toast(`PHP → ${e.target.value}`);
        } catch (err) { toast(err.message, 'err'); route(); }
    });
    main().querySelector('#diag')?.addEventListener('click', async () => {
        const box = main().querySelector('#diagbox');
        box.innerHTML = `<div class="empty">${t('assistant.thinking')}</div>`;
        try {
            const r = await api(`/assistant/diagnose/${id}`, { method: 'POST' });
            let answer = r.answer;
            if (r.task_id) { // lokalni mod (claude CLI) — async, pollaj task
                const res = await pollAiTask(r.task_id);
                if (res.status !== 'done') throw new Error(res.error || 'assistant_failed');
                answer = res.output;
            }
            box.innerHTML = `<div class="task-output" style="white-space:pre-wrap">${esc(answer)}</div>`;
        } catch (err) {
            if (err.message === 'assistant_not_configured') {
                box.innerHTML = `<div class="alert warn">${t('assistant.not_configured')}
                    <button class="btn sm" id="aigo">${icon('sparkle')}Forge AI</button></div>`;
                box.querySelector('#aigo')?.addEventListener('click', () => toggleAiDrawer(true));
            } else {
                box.innerHTML = `<div class="alert err">${esc(err.message)}</div>`;
            }
        }
    });
    main().querySelector('#renew').addEventListener('click', async () => {
        try {
            const r = await api(`/vhosts/${id}/ssl/renew`, { method: 'POST' });
            watchTask(r.task_id, `ssl.issue ${vhost.domain}`);
        } catch (err) { toast(err.message, 'err'); }
    });
    main().querySelector('#del').addEventListener('click', async () => {
        if (!confirm(`${t('common.confirm_delete')} (${vhost.domain})`)) return;
        try {
            const r = await api(`/vhosts/${id}`, { method: 'DELETE' });
            watchTask(r.task_id, `vhost.delete ${vhost.domain}`);
            location.hash = '#/websites';
        } catch (err) { toast(err.message, 'err'); }
    });

    main().querySelector('#backend').addEventListener('change', async (e) => {
        try {
            const r = await api(`/vhosts/${id}/backend`, { method: 'PUT', body: { web_backend: e.target.value } });
            watchTask(r.task_id, `backend ${vhost.domain}`);
        } catch (err) { toast(err.message, 'err'); route(); }
    });
    main().querySelector('#bkp').addEventListener('click', async () => {
        try {
            const r = await api(`/vhosts/${id}/backups`, { method: 'POST', body: {} });
            watchTask(r.task_id, `backup ${vhost.domain}`);
        } catch (err) { toast(err.message, 'err'); }
    });

    fileManager(vhost, main().querySelector('#fm'), '/httpdocs');
    cronSection(vhost, main().querySelector('#cron'));
    ftpSection(vhost, main().querySelector('#ftp'));
    gitSection(vhost, main().querySelector('#git'));
    stagingSection(vhost, main().querySelector('#staging'));
    appsSection(vhost, main().querySelector('#apps'));
    if (state.me.role === 'admin') terminalSection(vhost, main().querySelector('#term'));
    main().querySelector('#newstg').addEventListener('click', async () => {
        const sub = prompt(t('staging.prompt'), 'staging');
        if (!sub) return;
        try {
            const r = await api(`/vhosts/${id}/staging`, { method: 'POST', body: { subdomain: sub } });
            watchTask(r.task_id, `staging ${r.staging_domain}`);
        } catch (err) { toast(err.message, 'err'); }
    });
}

function terminalSection(vhost, container) {
    container.innerHTML = `
        <div class="hint mb">${t('term.sandbox_note')}</div>
        <div style="display:flex;gap:8px">
            <input id="tcmd" class="mono" style="flex:1" placeholder="ls -la" autocomplete="off">
            <button class="btn primary" id="trun">${t('term.run')}</button>
        </div>
        <div class="task-output mt" id="tout" style="min-height:60px"></div>`;
    const run = async () => {
        const command = container.querySelector('#tcmd').value.trim();
        if (!command) return;
        const out = container.querySelector('#tout');
        out.textContent = '…';
        try {
            const r = await api(`/vhosts/${vhost.id}/terminal`, { method: 'POST', body: { command } });
            out.textContent = `$ ${command}\n${r.output || ''}\n[exit ${r.exit_code}]`;
        } catch (err) { out.textContent = '✕ ' + err.message; }
    };
    container.querySelector('#trun').addEventListener('click', run);
    container.querySelector('#tcmd').addEventListener('keydown', (e) => { if (e.key === 'Enter') run(); });
}

function appsSection(vhost, container) {
    container.innerHTML = `
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn primary" id="wpinstall">${icon('box')}${t('apps.install_wp')}</button>
            <button class="btn" id="wpcheck">${icon('shield')}${t('apps.wp_integrity')}</button>
        </div>
        <div id="appsresult" class="mt"></div>`;
    container.querySelector('#wpinstall').addEventListener('click', async () => {
        const db = prompt(t('apps.wp_db_prompt'), 'wp_' + vhost.domain.replace(/[^a-z0-9]/g, '_').slice(0, 40));
        if (!db) return;
        try {
            const r = await api(`/vhosts/${vhost.id}/apps/wordpress`, { method: 'POST', body: { db_name: db } });
            watchTask(r.task_id, `WordPress ${vhost.domain}`);
            container.querySelector('#appsresult').innerHTML = `<div class="alert ok">${t('apps.wp_db_created')}: <span class="mono">${esc(db)}</span> / <span class="mono">${esc(r.db_password)}</span></div>`;
        } catch (err) { toast(err.message, 'err'); }
    });
    container.querySelector('#wpcheck').addEventListener('click', async () => {
        try {
            const r = await api(`/vhosts/${vhost.id}/apps/wordpress/checksums`, { method: 'POST' });
            watchTask(r.task_id, `WP integritet ${vhost.domain}`);
        } catch (err) { toast(err.message, 'err'); }
    });
}

async function stagingSection(vhost, container) {
    try {
        const envs = await api(`/vhosts/${vhost.id}/staging`);
        container.innerHTML = envs.length ? `
            <table class="data"><tbody>
            ${envs.map((e) => `<tr>
                <td class="mono"><a href="https://${esc(e.staging_domain)}" target="_blank">${esc(e.staging_domain)}</a></td>
                <td class="hide-sm">${fmtDate(e.last_sync)}</td>
            </tr>`).join('')}</tbody></table>` : `<div class="empty">${t('staging.none')}</div>`;
    } catch (err) { container.innerHTML = `<div class="alert err">${esc(err.message)}</div>`; }
}

// ---------------------------------------------------------------- git deploy
async function gitSection(vhost, container) {
    let repo;
    try { repo = await api(`/vhosts/${vhost.id}/git`); }
    catch (err) { container.innerHTML = `<div class="alert err">${esc(err.message)}</div>`; return; }

    container.innerHTML = `
    ${repo ? `
    <table class="data"><tbody>
        <tr><td>Repo</td><td class="mono" style="word-break:break-all">${esc(repo.repo_url)} <span class="badge">${esc(repo.branch)}</span></td></tr>
        <tr><td>${t('git.last_deploy')}</td><td class="mono">${repo.last_commit ? esc(repo.last_commit.slice(0, 10)) + ' · ' + fmtDate(repo.last_deploy_at) : '—'}</td></tr>
        <tr><td>Deploy key</td><td><div class="task-output" style="max-height:80px">${esc(repo.deploy_key ?? '')}</div></td></tr>
        <tr><td>Webhook</td><td class="mono" style="word-break:break-all">/api/v1/git/webhook/${esc(repo.webhook_secret)}</td></tr>
    </tbody></table>
    <div class="dialog-foot" style="justify-content:flex-start">
        <button class="btn primary" id="gdeploy">${icon('refresh')}${t('git.deploy_now')}</button>
        <button class="btn danger" id="gremove">${t('common.delete')}</button>
    </div>` : ''}
    <form id="gf" class="mt">
        <div class="grid cols-2">
            <div class="field"><label>${t('git.repo_url')}</label>
                <input name="repo_url" required class="mono" placeholder="git@github.com:user/repo.git"
                    value="${esc(repo?.repo_url ?? '')}"></div>
            <div class="field"><label>Branch</label>
                <input name="branch" class="mono" value="${esc(repo?.branch ?? 'main')}"></div>
        </div>
        <button class="btn primary">${repo ? t('common.save') : t('git.connect')}</button>
    </form>`;

    container.querySelector('#gf').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            const r = await api(`/vhosts/${vhost.id}/git`, { method: 'POST', body: Object.fromEntries(new FormData(e.target)) });
            openModal(`
                <div class="dialog-head"><h1>${t('git.connected')}</h1><button class="btn ghost icon" data-close>${icon('x')}</button></div>
                <div class="field"><label>Deploy key (dodaj u repo kao read-only key)</label>
                    <div class="task-output">${esc(r.public_key)}</div></div>
                <div class="field"><label>Webhook URL (auto-deploy na push)</label>
                    <div class="task-output">${esc(r.webhook_url)}</div></div>`, { wide: true });
            gitSection(vhost, container);
        } catch (err) { toast(err.message, 'err'); }
    });
    container.querySelector('#gdeploy')?.addEventListener('click', async () => {
        try {
            const r = await api(`/vhosts/${vhost.id}/git/deploy`, { method: 'POST' });
            watchTask(r.task_id, `git.deploy ${vhost.domain}`);
        } catch (err) { toast(err.message, 'err'); }
    });
    container.querySelector('#gremove')?.addEventListener('click', async () => {
        if (!confirm(t('common.confirm_delete'))) return;
        try { await api(`/vhosts/${vhost.id}/git`, { method: 'DELETE' }); gitSection(vhost, container); }
        catch (err) { toast(err.message, 'err'); }
    });
}

// ---------------------------------------------------------------- FTP
async function ftpSection(vhost, container) {
    let users;
    try { users = await api(`/vhosts/${vhost.id}/ftp`); }
    catch (err) { container.innerHTML = `<div class="alert err">${esc(err.message)}</div>`; return; }

    container.innerHTML = `
    ${users.length ? `
    <table class="data"><tbody>
        ${users.map((u) => `<tr>
            <td class="mono">${esc(u.username)}</td>
            <td class="mono" style="word-break:break-all">${esc(u.home_path)}</td>
            <td class="num"><button class="btn danger" data-del="${u.id}">${t('common.delete')}</button></td>
        </tr>`).join('')}
    </tbody></table>` : `<div class="empty-row">${t('ftp.empty')}</div>`}
    <form id="ftpf" class="addform">
        <div class="addform-h">${t('ftp.add')}</div>
        <div class="addform-grid">
            <div class="field"><label>${t('ftp.username')}</label><input name="username" required pattern="[a-z][a-z0-9_.\\-]{2,31}" class="mono"></div>
            <div class="field"><label>${t('auth.password')}</label><input name="password" type="password" required minlength="12"></div>
            <div class="field span2"><label>${t('ftp.home')}</label><input name="home" value="/httpdocs" class="mono"></div>
        </div>
        <div class="addform-foot"><button class="btn primary">${icon('plus')}${t('common.create')}</button></div>
    </form>`;

    container.querySelector('#ftpf').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            await api(`/vhosts/${vhost.id}/ftp`, { method: 'POST', body: Object.fromEntries(new FormData(e.target)) });
            toast(t('ftp.created'));
            ftpSection(vhost, container);
        } catch (err) { toast(err.message, 'err'); }
    });
    container.querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', async () => {
        if (!confirm(t('common.confirm_delete'))) return;
        try { await api(`/vhosts/${vhost.id}/ftp/${b.dataset.del}`, { method: 'DELETE' }); ftpSection(vhost, container); }
        catch (err) { toast(err.message, 'err'); }
    }));
}

// ---------------------------------------------------------------- cron
async function cronSection(vhost, container) {
    let jobs;
    try { jobs = await api(`/vhosts/${vhost.id}/cron`); }
    catch (err) { container.innerHTML = `<div class="alert err">${esc(err.message)}</div>`; return; }

    container.innerHTML = `
    ${jobs.length ? `
    <table class="data"><thead><tr>
        <th>${t('cron.schedule')}</th><th>${t('cron.command')}</th><th class="hide-sm">${t('cron.last_run')}</th><th>Status</th><th></th>
    </tr></thead><tbody>
        ${jobs.map((j) => `<tr>
            <td class="mono">${esc(j.schedule)}</td>
            <td class="mono">${esc(j.command)}</td>
            <td class="hide-sm">${fmtDate(j.last_run_at)}</td>
            <td><span class="badge ${Number(j.enabled) ? 'ok' : 'warn'}">${Number(j.enabled) ? t('cron.enabled') : t('cron.disabled')}</span></td>
            <td class="num">
                <button class="btn ghost" data-toggle="${j.id}">${Number(j.enabled) ? '⏸' : '▶'}</button>
                <button class="btn danger" data-del="${j.id}">${t('common.delete')}</button>
            </td></tr>`).join('')}
    </tbody></table>` : `<div class="empty-row">${t('cron.empty')}</div>`}
    <form id="cronf" class="addform">
        <div class="addform-h">${t('cron.add')}</div>
        <div class="addform-grid cron">
            <div class="field"><label>${t('cron.schedule')}</label>
                <input name="schedule" required placeholder="*/15 * * * *" class="mono">
                <span class="hint">min sat dan mjesec dan_u_tjednu</span></div>
            <div class="field"><label>${t('cron.command')}</label>
                <input name="command" required placeholder="php /var/www/vhosts/.../cron.php" class="mono"></div>
        </div>
        <div class="addform-foot"><button class="btn primary">${icon('plus')}${t('common.create')}</button></div>
    </form>`;

    container.querySelector('#cronf').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            await api(`/vhosts/${vhost.id}/cron`, { method: 'POST', body: Object.fromEntries(new FormData(e.target)) });
            cronSection(vhost, container);
        } catch (err) { toast(err.message, 'err'); }
    });
    container.querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', async () => {
        if (!confirm(t('common.confirm_delete'))) return;
        try { await api(`/vhosts/${vhost.id}/cron/${b.dataset.del}`, { method: 'DELETE' }); cronSection(vhost, container); }
        catch (err) { toast(err.message, 'err'); }
    }));
    container.querySelectorAll('[data-toggle]').forEach((b) => b.addEventListener('click', async () => {
        try { await api(`/vhosts/${vhost.id}/cron/${b.dataset.toggle}/toggle`, { method: 'PUT' }); cronSection(vhost, container); }
        catch (err) { toast(err.message, 'err'); }
    }));
}

// ---------------------------------------------------------------- file manager
async function fileManager(vhost, container, relPath) {
    container.innerHTML = `<div class="empty">${t('common.loading')}</div>`;
    let entries;
    try {
        ({ entries } = await api(`/vhosts/${vhost.id}/files/list`, { method: 'POST', body: { path: relPath } }));
    } catch (err) {
        container.innerHTML = `<div class="alert err">${esc(err.message)}</div>`;
        return;
    }

    const parts = relPath.split('/').filter(Boolean);
    container.innerHTML = `
    <div class="fm">
        <div class="fm-bar">
            <div class="crumbs">
                <a href="#" data-go="/">${esc(vhost.domain)}</a>
                ${parts.map((p, i) => `<span class="sep">/</span><a href="#" data-go="/${parts.slice(0, i + 1).join('/')}">${esc(p)}</a>`).join('')}
            </div>
            <button class="icon-btn" data-upload title="${t('files.upload')}">${icon('upload')}</button>
            <button class="icon-btn" data-mkdir title="${t('files.mkdir')}">${icon('folder')}</button>
            <input type="file" hidden>
        </div>
        <table><tbody>
            ${entries.map((en, i) => `
            <tr data-i="${i}">
                <td class="cell-icon">${icon(en.type === 'dir' ? 'folder' : 'file', 15)}</td>
                <td class="mono">${esc(en.name)}</td>
                <td class="meta num hide-sm">${en.type === 'file' ? fmtBytes(en.size_bytes) : ''}</td>
                <td class="meta hide-sm">${esc(en.mode)}</td>
                <td class="meta hide-sm">${fmtDate(en.mtime)}</td>
            </tr>`).join('') || `<tr><td><div class="empty">prazno</div></td></tr>`}
        </tbody></table>
    </div>`;

    container.querySelectorAll('[data-go]').forEach((a) => a.addEventListener('click', (e) => {
        e.preventDefault();
        fileManager(vhost, container, a.dataset.go === '/' ? '' : a.dataset.go);
    }));
    const fileInput = container.querySelector('input[type=file]');
    container.querySelector('[data-upload]').addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', async () => {
        const f = fileInput.files[0];
        if (!f) return;
        const fd = new FormData();
        fd.append('path', relPath);
        fd.append('file', f);
        try {
            const res = await fetch(`/api/v1/vhosts/${vhost.id}/files/upload`, {
                method: 'POST',
                headers: { Authorization: `Bearer ${state.token}` },
                body: fd,
            });
            const json = await res.json();
            if (!json.ok) throw new Error(json.error);
            toast(`${f.name}: ${t('files.uploaded')}`);
            fileManager(vhost, container, relPath);
        } catch (err) { toast(err.message, 'err'); }
    });
    container.querySelector('[data-mkdir]').addEventListener('click', async () => {
        const name = prompt('Naziv direktorija:');
        if (!name) return;
        try {
            await api(`/vhosts/${vhost.id}/files/mkdir`, { method: 'POST', body: { path: `${relPath}/${name}` } });
            fileManager(vhost, container, relPath);
        } catch (err) { toast(err.message, 'err'); }
    });
    container.querySelectorAll('[data-i]').forEach((tr) => tr.addEventListener('click', () => {
        const entry = entries[Number(tr.dataset.i)];
        const next = `${relPath}/${entry.name}`;
        entry.type === 'dir' ? fileManager(vhost, container, next) : openFileEditor(vhost, container, relPath, entry);
    }));
}

async function openFileEditor(vhost, container, relPath, entry) {
    const filePath = `${relPath}/${entry.name}`;
    let content = '';
    try {
        const r = await api(`/vhosts/${vhost.id}/files/read`, { method: 'POST', body: { path: filePath } });
        content = new TextDecoder().decode(Uint8Array.from(atob(r.content), (c) => c.charCodeAt(0)));
    } catch (err) { return toast(err.message, 'err'); }

    const modal = openModal(`
        <div class="dialog-head"><h1 class="mono">${esc(entry.name)}</h1><button class="btn ghost icon" data-close>${icon('x')}</button></div>
        <textarea class="code-edit" spellcheck="false">${esc(content)}</textarea>
        <div class="dialog-foot">
            <button class="btn danger" id="fdel">${t('common.delete')}</button>
            <button class="btn" id="fdl">${icon('download')}${t('files.download')}</button>
            <span class="spacer" style="flex:1"></span>
            <button class="btn" data-close>${t('common.cancel')}</button>
            <button class="btn primary" id="fsave">${t('common.save')}</button>
        </div>`, { wide: true });

    modal.querySelector('#fdl').addEventListener('click', async () => {
        try {
            const res = await fetch(`/api/v1/vhosts/${vhost.id}/files/download?path=${encodeURIComponent(filePath)}`, {
                headers: { Authorization: `Bearer ${state.token}` },
            });
            if (!res.ok) throw new Error('download_failed');
            const blob = await res.blob();
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = entry.name;
            a.click();
            URL.revokeObjectURL(a.href);
        } catch (err) { toast(err.message, 'err'); }
    });

    modal.querySelector('#fsave').addEventListener('click', async () => {
        const text = modal.querySelector('textarea').value;
        const b64 = btoa(String.fromCharCode(...new TextEncoder().encode(text)));
        try {
            await api(`/vhosts/${vhost.id}/files/write`, { method: 'POST', body: { path: filePath, content_b64: b64 } });
            toast(`${entry.name}: spremljeno`);
            modal.close();
        } catch (err) { toast(err.message, 'err'); }
    });
    modal.querySelector('#fdel').addEventListener('click', async () => {
        if (!confirm(`${t('common.confirm_delete')} (${entry.name})`)) return;
        try {
            await api(`/vhosts/${vhost.id}/files/delete`, { method: 'POST', body: { path: filePath } });
            modal.close();
            fileManager(vhost, container, relPath);
        } catch (err) { toast(err.message, 'err'); }
    });
}

// ---------------------------------------------------------------- datoteke (samostalna stranica)
async function pageFiles() {
    setActive('files');
    main().innerHTML = `<div class="empty">${t('common.loading')}</div>`;
    const vhosts = await api('/vhosts');
    if (!vhosts.length) {
        main().innerHTML = `<div class="card"><div class="empty">${t('files.no_vhosts')}</div></div>`;
        return;
    }
    const saved = Number(sessionStorage.getItem('fp_files_vhost'));
    const current = vhosts.find((v) => v.id === saved) ?? vhosts[0];
    main().innerHTML = `
    <div class="page-head">
        <select id="fvh" class="input mono">${vhosts.map((v) =>
            `<option value="${v.id}" ${v.id === current.id ? 'selected' : ''}>${esc(v.domain)}</option>`).join('')}</select>
        <span class="hint mono">${esc(current.docroot ?? '')}</span>
    </div>
    <div id="fmwrap"></div>`;
    fileManager(current, main().querySelector('#fmwrap'), '/httpdocs');
    main().querySelector('#fvh').addEventListener('change', (e) => {
        sessionStorage.setItem('fp_files_vhost', e.target.value);
        pageFiles();
    });
}

// ---------------------------------------------------------------- databases / ssl / tasks / monitoring
async function pageDatabases() {
    setActive('databases');
    main().innerHTML = `
    <div class="page-head"><div class="spacer"></div>
        <button class="btn primary" id="new">${icon('plus')}${t('db.create')}</button></div>
    <div class="card">${t('common.loading')}</div>`;
    document.getElementById('new').addEventListener('click', createDbModal);

    const dbs = await api('/databases');
    main().querySelector('.card').innerHTML = dbs.length ? `
        <table class="data"><thead><tr><th>${t('db.name')}</th><th class="hide-sm">Veličina</th><th class="hide-sm">Kreirano</th><th></th></tr></thead><tbody>
        ${dbs.map((d) => `<tr>
            <td class="mono">${esc(d.name)}</td>
            <td class="mono hide-sm">${fmtBytes(d.size_bytes)}</td>
            <td class="hide-sm">${fmtDate(d.created_at)}</td>
            <td class="num"><button class="btn ghost" data-pma="${d.id}">${t('db.pma')}</button>
                <button class="btn ghost" data-user="${d.id}">+ ${t('db.user')}</button>
                <button class="btn danger" data-del="${d.id}" data-name="${esc(d.name)}">${t('common.delete')}</button></td>
        </tr>`).join('')}</tbody></table>` : `<div class="empty">${t('nav.databases')}: 0</div>`;

    main().querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', async () => {
        if (!confirm(`${t('common.confirm_delete')} (${b.dataset.name})`)) return;
        try { await api(`/databases/${b.dataset.del}`, { method: 'DELETE' }); pageDatabases(); }
        catch (err) { toast(err.message, 'err'); }
    }));
    main().querySelectorAll('[data-user]').forEach((b) => b.addEventListener('click', () => createDbUserModal(b.dataset.user)));
    // phpMyAdmin auto-login: jednokratan signed token → nova kartica
    main().querySelectorAll('[data-pma]').forEach((b) => b.addEventListener('click', async () => {
        try {
            const r = await api(`/databases/${b.dataset.pma}/pma`, { method: 'POST' });
            window.open(r.url, '_blank', 'noopener');
        } catch (err) { toast(t('db.pma_' + err.message) !== 'db.pma_' + err.message ? t('db.pma_' + err.message) : err.message, 'err'); }
    }));
}

function createDbModal() {
    const modal = openModal(`
        <div class="dialog-head"><h1>${t('db.create')}</h1><button class="btn ghost icon" data-close>${icon('x')}</button></div>
        <form id="df">
            <div class="field"><label>${t('db.name')}</label>
                <input name="name" required pattern="[a-z][a-z0-9_]{2,63}" class="mono" autocomplete="off">
                <span class="hint">snake_case, npr. moja_baza</span></div>
            <div class="dialog-foot"><button type="button" class="btn" data-close>${t('common.cancel')}</button>
                <button class="btn primary">${t('common.create')}</button></div>
        </form>`);
    modal.querySelector('#df').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            await api('/databases', { method: 'POST', body: Object.fromEntries(new FormData(e.target)) });
            modal.close();
            location.hash = '#/databases'; route();
        } catch (err) { toast(err.message, 'err'); }
    });
}

function createDbUserModal(dbId) {
    const modal = openModal(`
        <div class="dialog-head"><h1>${t('db.user')}</h1><button class="btn ghost icon" data-close>${icon('x')}</button></div>
        <form id="uf">
            <div class="field"><label>Korisničko ime</label><input name="username" required pattern="[a-z][a-z0-9_]{2,31}" class="mono"></div>
            <div class="field"><label>${t('auth.password')}</label><input name="password" type="password" required minlength="12">
                <span class="hint">min. 12 znakova</span></div>
            <div class="dialog-foot"><button type="button" class="btn" data-close>${t('common.cancel')}</button>
                <button class="btn primary">${t('common.create')}</button></div>
        </form>`);
    modal.querySelector('#uf').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            await api(`/databases/${dbId}/users`, { method: 'POST', body: Object.fromEntries(new FormData(e.target)) });
            toast('DB user kreiran');
            modal.close();
        } catch (err) { toast(err.message, 'err'); }
    });
}

async function pageSsl() {
    setActive('ssl');
    main().innerHTML = `${tabsHtml('protect', 'ssl')}<div class="card">${t('common.loading')}</div>`;
    const certs = await api('/ssl');
    main().querySelector('.card').innerHTML = certs.length ? `
        <table class="data"><thead><tr><th>Hostname</th><th class="hide-sm">Tip</th><th>${t('ssl.expires')}</th><th>Status</th><th></th></tr></thead><tbody>
        ${certs.map((c) => {
            const days = Math.floor((new Date(String(c.expires_at).replace(' ', 'T')) - Date.now()) / 864e5);
            return `<tr>
                <td class="mono">${esc(c.hostname)}</td>
                <td class="mono hide-sm">${esc(c.type)}${Number(c.auto_renew) ? ' · auto' : ''}</td>
                <td>${fmtDate(c.expires_at)} <span class="badge ${days < 14 ? 'err' : days < 30 ? 'warn' : 'ok'}">${days} d</span></td>
                <td>${statusBadge(c.status)}</td>
                <td class="num">${c.vhost_id ? `
                    <button class="btn ghost" data-renew="${c.vhost_id}">${t('ssl.renew_le')}</button>
                    <button class="btn ghost" data-custom="${c.vhost_id}" data-host="${esc(c.hostname)}">${t('ssl.custom')}</button>` : ''}</td></tr>`;
        }).join('')}</tbody></table>` : `<div class="empty">${t('nav.ssl')}: 0</div>`;

    main().querySelectorAll('[data-renew]').forEach((b) => b.addEventListener('click', async () => {
        try {
            const r = await api(`/vhosts/${b.dataset.renew}/ssl/renew`, { method: 'POST' });
            watchTask(r.task_id, 'ssl.issue');
            toast(t('ssl.renew_started'));
        } catch (err) { toast(err.message, 'err'); }
    }));
    main().querySelectorAll('[data-custom]').forEach((b) => b.addEventListener('click', () =>
        customCertModal(b.dataset.custom, b.dataset.host)));
}

// Ručna instalacija kupljenog certifikata (PEM) — validaciju radi agent prije zapisa
function customCertModal(vhostId, hostname) {
    const modal = openModal(`
        <div class="dialog-head"><h1>${t('ssl.custom_title')} <span class="mono">${esc(hostname)}</span></h1>
            <button class="btn ghost icon" data-close>${icon('x')}</button></div>
        <form id="cf">
            <div class="field"><label>${t('ssl.cert_pem')}</label>
                <textarea name="cert_pem" required rows="6" class="mono" placeholder="-----BEGIN CERTIFICATE-----"></textarea></div>
            <div class="field"><label>${t('ssl.key_pem')}</label>
                <textarea name="key_pem" required rows="6" class="mono" placeholder="-----BEGIN PRIVATE KEY-----"></textarea>
                <span class="hint">${t('ssl.key_hint')}</span></div>
            <div class="field"><label>${t('ssl.chain_pem')}</label>
                <textarea name="chain_pem" rows="4" class="mono" placeholder="${t('ssl.chain_hint')}"></textarea></div>
            <div class="dialog-foot"><button type="button" class="btn" data-close>${t('common.cancel')}</button>
                <button class="btn primary">${t('ssl.install')}</button></div>
        </form>`, { wide: true });
    modal.querySelector('#cf').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            const r = await api(`/vhosts/${vhostId}/ssl/custom`, { method: 'POST', body: Object.fromEntries(new FormData(e.target)) });
            toast(`${t('ssl.installed')} (${r.issuer}, ${fmtDate(r.expires_at)})`);
            modal.close();
            pageSsl();
        } catch (err) { toast(err.message, 'err'); }
    });
}

async function pageTasks() {
    setActive('tasks');
    main().innerHTML = `${tabsHtml('monitoring', 'tasks')}<div class="card">${t('common.loading')}</div>`;
    const tasks = await api('/tasks');
    main().querySelector('.card').innerHTML = tasks.length ? `
        <table class="data"><thead><tr><th>#</th><th>Operacija</th><th>Status</th><th class="hide-sm">Progress</th><th class="hide-sm">Kreirano</th><th class="hide-sm">Završeno</th></tr></thead><tbody>
        ${tasks.map((task) => `<tr class="row-link" data-task="${task.id}">
            <td class="mono">${task.id}</td>
            <td class="mono">${esc(task.op)}</td>
            <td>${statusBadge(task.status)}</td>
            <td class="hide-sm"><div class="progress"><div style="width:${task.progress}%"></div></div></td>
            <td class="hide-sm">${fmtDate(task.created_at)}</td>
            <td class="hide-sm">${fmtDate(task.finished_at)}</td></tr>`).join('')}
        </tbody></table>` : `<div class="empty">${t('nav.tasks')}: 0</div>`;

    main().querySelectorAll('[data-task]').forEach((tr) => tr.addEventListener('click', async () => {
        const task = await api(`/tasks/${tr.dataset.task}`);
        openModal(`
            <div class="dialog-head"><h1 class="mono">#${task.id} ${esc(task.op)}</h1>
                <button class="btn ghost icon" data-close>${icon('x')}</button></div>
            <div class="task-output">${esc(task.output ?? '')}${task.error ? '\n[GREŠKA] ' + esc(task.error) : ''}</div>`, { wide: true });
    }));
}

function sparkline(points, { height = 130, formatY = (v) => String(v), color = 'var(--accent)' } = {}) {
    if (points.length < 2) return `<div class="empty">${t('common.loading')}</div>`;
    const values = points.map((p) => Number(p.value));
    const max = Math.max(...values) * 1.1 || 1;
    const width = 600;
    const coords = values.map((v, i) =>
        `${(i / (values.length - 1)) * width},${height - (v / max) * (height - 8)}`).join(' ');
    const gridLines = [1, 2, 3, 4].map((i) =>
        `<line x1="0" x2="${width}" y1="${(i / 5) * height}" y2="${(i / 5) * height}" stroke="var(--line-2)" stroke-width="1" vector-effect="non-scaling-stroke"/>`).join('');
    return `
    <svg viewBox="0 0 ${width} ${height}" preserveAspectRatio="none" class="chart" role="img">
        ${gridLines}
        <polygon points="0,${height} ${coords} ${width},${height}" fill="${color}" opacity="0.1"/>
        <polyline points="${coords}" fill="none" stroke="${color}" stroke-width="1.8"
            vector-effect="non-scaling-stroke" stroke-linejoin="round"/>
    </svg>
    <div class="chart-meta mono">max ${formatY(max / 1.1)} · ${points.length} točaka</div>`;
}

const bps = (v) => `${fmtBytes(v)}/s`;
const lastVal = (arr) => (arr.length ? Number(arr.at(-1).value) : null);

const MON_RANGES = ['15m', '1h', '6h', '24h', '7d', '30d'];
const MON_REFRESH = ['0', '5', '10', '30', '60']; // sekunde; 0 = isključeno

async function refreshMonitoring() {
    const data = document.getElementById('mondata');
    if (!data) return;
    const r = state.monRange;
    const h = (metric) => api(`/monitoring/history?metric=${metric}&range=${r}`).catch(() => []);
    try {
        const [m, services, top, cpuPct, cpuLoad, mem, dRead, dWrite, netRx, netTx] = await Promise.all([
            api('/monitoring/now'),
            api('/monitoring/services').catch(() => ({})),
            api('/monitoring/top').catch(() => ({ processes: [] })),
            h('cpu_pct'), h('cpu_load1'), h('mem_used_bytes'),
            h('disk_read_bps'), h('disk_write_bps'), h('net_rx_bps'), h('net_tx_bps'),
        ]);
        const cpuIsPct = cpuPct.length >= 2;
        const cpuSeries = cpuIsPct ? cpuPct : cpuLoad;
        const cpuNow = m.cpu_pct ?? (lastVal(cpuPct) ?? 0);

        // per-core trake
        const coresHtml = (m.cores || []).map((c, i) => `
            <div class="core"><div class="core-head"><span class="mono">c${i}</span><span class="mono">${c.toFixed(0)}%</span></div>
                <div class="core-bar"><div class="core-fill" style="width:${c}%;background:${c > 80 ? 'var(--danger)' : c > 50 ? 'var(--warn)' : 'var(--accent)'}"></div></div></div>`).join('');

        // memorijska razrada
        const tot = m.mem_total_bytes || 1;
        const apps = m.mem_apps_bytes ?? (m.mem_total_bytes - m.mem_available_bytes);
        const cache = m.mem_cache_bytes ?? 0;
        const free = m.mem_free_bytes ?? m.mem_available_bytes;
        const memBar = `<div class="membar">
            <span style="width:${apps / tot * 100}%;background:var(--info)" title="${t('mon.apps')}"></span>
            <span style="width:${cache / tot * 100}%;background:var(--accent)" title="${t('mon.cache')}"></span>
        </div><div class="memlegend">
            <span><i style="background:var(--info)"></i>${t('mon.apps')} ${fmtBytes(apps)}</span>
            <span><i style="background:var(--accent)"></i>${t('mon.cache')} ${fmtBytes(cache)}</span>
            <span><i style="background:var(--line-strong)"></i>${t('mon.free')} ${fmtBytes(free)}</span></div>`;

        const svcRows = Object.entries(services).map(([name, s]) => {
            const st = s.ActiveState || 'unknown';
            return `<tr><td class="mono">${esc(name)}</td>
                <td><span class="badge ${st === 'active' ? 'ok' : (st === 'failed' ? 'err' : '')}">${esc(s.SubState || st)}</span></td>
                <td class="mono num">${s.cpu_pct != null ? s.cpu_pct.toFixed(1) + '%' : '—'}</td>
                <td class="mono num">${s.mem_bytes != null ? fmtBytes(s.mem_bytes) : '—'}</td>
                <td class="mono num hide-sm">${svcUptime(s)}</td></tr>`;
        }).join('');

        const procRows = (top.processes || []).map((p) => `<tr>
            <td class="mono num">${p.pid}</td>
            <td class="mono" style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(p.args)}">${esc(p.comm)}</td>
            <td class="mono hide-sm">${esc(p.user)}</td>
            <td class="mono num">${p.cpu_pct.toFixed(1)}%</td>
            <td class="mono num">${fmtBytes(p.rss_bytes)}</td></tr>`).join('');

        const chart = (title, series, opts, now) => `
            <div class="card flush"><div class="card-head"><h2>${title}</h2><span class="spacer"></span>
                <span class="num" style="font-size:var(--fs-sm);color:var(--ink-2)">${now}</span></div>
                <div class="pad">${sparkline(series, opts)}</div></div>`;

        data.innerHTML = `
        <div class="grid cols-2">
            <div class="card flush"><div class="card-head"><h2>CPU</h2><span class="spacer"></span>
                <span class="num" style="font-size:var(--fs-sm);color:var(--ink-2)">${cpuNow.toFixed(0)}% · load ${m.load[0].toFixed(2)}</span></div>
                <div class="pad">${sparkline(cpuIsPct ? cpuSeries : cpuSeries, { formatY: (v) => (cpuIsPct ? v.toFixed(0) + '%' : v.toFixed(2)) })}</div>
                ${coresHtml ? `<div class="cores">${coresHtml}</div>` : ''}</div>
            <div class="card flush"><div class="card-head"><h2>${t('mon.membreak')}</h2><span class="spacer"></span>
                <span class="num" style="font-size:var(--fs-sm);color:var(--ink-2)">${fmtBytes(m.mem_total_bytes - m.mem_available_bytes)} / ${fmtBytes(m.mem_total_bytes)}</span></div>
                <div class="pad">${sparkline(mem, { formatY: fmtBytes, color: 'var(--info)' })}</div>
                ${memBar}</div>
            ${chart('Disk čitanje', dRead, { formatY: bps, color: 'var(--ok)' }, lastVal(dRead) != null ? bps(lastVal(dRead)) : '—')}
            ${chart('Disk pisanje', dWrite, { formatY: bps, color: 'var(--warn)' }, lastVal(dWrite) != null ? bps(lastVal(dWrite)) : '—')}
            ${chart('Mreža ↓', netRx, { formatY: bps, color: 'var(--accent)' }, lastVal(netRx) != null ? bps(lastVal(netRx)) : '—')}
            ${chart('Mreža ↑', netTx, { formatY: bps, color: 'var(--info)' }, lastVal(netTx) != null ? bps(lastVal(netTx)) : '—')}
        </div>
        <div class="grid split mt">
            <div class="card flush"><div class="card-head"><h2>${t('mon.top')}</h2><span class="spacer"></span>
                <span class="num" style="font-size:var(--fs-xs);color:var(--ink-3)">ps · CPU</span></div>
                <table class="data"><thead><tr><th>PID</th><th>${t('mon.service')}</th><th class="hide-sm">${t('mon.user')}</th><th class="num">CPU</th><th class="num">RAM</th></tr></thead>
                <tbody>${procRows || `<tr><td colspan="5" class="empty">—</td></tr>`}</tbody></table></div>
            <div class="card flush"><div class="card-head"><h2>${t('mon.services')}</h2></div>
                <table class="data"><thead><tr><th>${t('mon.service')}</th><th>${t('mon.state')}</th><th class="num">CPU</th><th class="num">RAM</th><th class="num hide-sm">Uptime</th></tr></thead>
                <tbody>${svcRows}</tbody></table></div>
        </div>`;
    } catch {
        data.innerHTML = `<div class="empty">Dostupno administratoru.</div>`;
    }
}

function scheduleMonitoring() {
    if (state.monTimer) { clearInterval(state.monTimer); state.monTimer = null; }
    const sec = Number(state.monRefresh);
    if (sec > 0) state.monTimer = setInterval(refreshMonitoring, sec * 1000);
}

async function pageMonitoring() {
    setActive('monitoring');
    if (!MON_RANGES.includes(state.monRange)) state.monRange = '1h';
    const pills = MON_RANGES.map((v) => `<button class="pill ${state.monRange === v ? 'active' : ''}" data-range="${v}">${v}</button>`).join('');
    const refOpt = (v) => `<option value="${v}" ${state.monRefresh === v ? 'selected' : ''}>${v === '0' ? t('mon.off') : v + 's'}</option>`;
    main().innerHTML = `
        ${tabsHtml('monitoring', 'monitoring')}
        <div class="page-head" style="gap:12px;align-items:center">
            <div class="pillbar">${pills}</div>
            <span class="spacer"></span>
            <span class="live-dot">${Number(state.monRefresh) > 0 ? dot('ok', true) + ' streaming · ' + state.monRefresh + 's' : 'pauzirano'}</span>
            <label class="inline mono" style="gap:6px">${t('mon.refresh')}
                <select id="monref" class="mono">${MON_REFRESH.map(refOpt).join('')}</select></label>
        </div>
        <div id="mondata"><div class="empty">${t('common.loading')}</div></div>`;

    main().querySelectorAll('[data-range]').forEach((b) => b.addEventListener('click', () => {
        state.monRange = b.dataset.range;
        localStorage.setItem('fp_mon_range', state.monRange);
        main().querySelectorAll('[data-range]').forEach((x) => x.classList.toggle('active', x === b));
        refreshMonitoring();
    }));
    document.getElementById('monref').addEventListener('change', (e) => {
        state.monRefresh = e.target.value;
        localStorage.setItem('fp_mon_refresh', state.monRefresh);
        scheduleMonitoring();
        pageMonitoring(); // osvježi "streaming" indikator
    });

    await refreshMonitoring();
    scheduleMonitoring();
}

// ---------------------------------------------------------------- Cloudflare
async function pageCloudflare() {
    setActive('protect');
    main().innerHTML = `${tabsHtml('protect', 'cloudflare')}<div class="empty">${t('common.loading')}</div>`;
    const acct = await api('/cloudflare/account').catch(() => ({ connected: false }));

    if (!acct.connected) {
        main().innerHTML = `${tabsHtml('protect', 'cloudflare')}
        <div class="card" style="max-width:560px">
            <div class="card-head"><h2>${t('cf.title')}</h2></div>
            <p class="hint" style="margin:0 0 var(--gap)">${t('cf.intro')}</p>
            <form id="cff">
                <div class="field"><label>${t('cf.token')}</label>
                    <input name="api_token" required class="mono" placeholder="••••••••••••••••" autocomplete="off">
                    <span class="hint">${t('cf.token_hint')}</span></div>
                <button class="btn primary">${icon('cloud')}${t('cf.connect')}</button>
            </form>
        </div>`;
        main().querySelector('#cff').addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                await api('/cloudflare/account', { method: 'POST', body: Object.fromEntries(new FormData(e.target)) });
                toast(t('cf.connected'), 'ok'); pageCloudflare();
            } catch (err) { toast(err.message, 'err'); }
        });
        return;
    }

    const [zones, vhosts] = await Promise.all([
        api('/cloudflare/zones').catch(() => []),
        api('/vhosts').catch(() => []),
    ]);
    const zoneOpts = zones.map((z) => `<option value="${esc(z.id)}">${esc(z.name)}</option>`).join('');

    main().innerHTML = `${tabsHtml('protect', 'cloudflare')}
    <div class="page-head">
        <span class="badge ok">${icon('cloud')} ${t('cf.connected')}</span><span class="spacer"></span>
        <button class="btn danger" id="cfdis">${t('cf.disconnect')}</button>
    </div>
    <div class="card"><div class="card-head"><h2>${t('cf.zones')}</h2></div>
        ${zones.length ? `<table class="data"><thead><tr><th>${t('cf.zone')}</th><th>${t('cf.status')}</th><th class="mono hide-sm">Zone ID</th></tr></thead>
        <tbody>${zones.map((z) => `<tr><td class="mono">${esc(z.name)}</td>
            <td><span class="badge ${z.status === 'active' ? 'ok' : 'warn'}">${esc(z.status)}</span></td>
            <td class="mono hide-sm" style="font-size:var(--fs-sm)">${esc(z.id)}</td></tr>`).join('')}</tbody></table>`
        : `<div class="empty">${t('cf.zones')}: 0</div>`}</div>

    <div class="card mt"><div class="card-head"><h2>${t('cf.sync')}</h2></div>
        ${vhosts.length && zones.length ? `<table class="data"><thead><tr><th>${t('vhost.domain')}</th><th>${t('cf.zone')}</th><th></th></tr></thead>
        <tbody>${vhosts.map((v) => `<tr data-vid="${v.id}">
            <td class="mono">${esc(v.domain)}</td>
            <td><select class="mono cf-zone">${zoneOpts}</select>
                <label class="inline" style="margin-left:8px"><input type="checkbox" class="cf-proxy" checked> ${t('cf.proxy')}</label></td>
            <td class="num">
                <button class="btn cf-sync">${t('cf.sync')}</button>
                <button class="btn cf-purge">${t('cf.purge')}</button></td>
        </tr>`).join('')}</tbody></table>`
        : `<div class="empty">${vhosts.length ? t('cf.zones') + ': 0' : t('nav.websites') + ': 0'}</div>`}</div>`;

    main().querySelector('#cfdis').addEventListener('click', async () => {
        if (!confirm(t('common.confirm_delete'))) return;
        try { await api('/cloudflare/account', { method: 'DELETE' }); pageCloudflare(); }
        catch (err) { toast(err.message, 'err'); }
    });
    main().querySelectorAll('tr[data-vid]').forEach((tr) => {
        const vid = tr.dataset.vid;
        const zoneId = () => tr.querySelector('.cf-zone').value;
        tr.querySelector('.cf-sync').addEventListener('click', async (e) => {
            e.target.disabled = true;
            try {
                const r = await api(`/vhosts/${vid}/cloudflare/sync`, { method: 'POST',
                    body: { zone_id: zoneId(), proxy: tr.querySelector('.cf-proxy').checked } });
                toast(t('cf.sync_done') + ': ' + (r.created || []).join(', '), 'ok');
            } catch (err) { toast(err.message, 'err'); } finally { e.target.disabled = false; }
        });
        tr.querySelector('.cf-purge').addEventListener('click', async (e) => {
            e.target.disabled = true;
            try { await api(`/vhosts/${vid}/cloudflare/purge`, { method: 'POST', body: {} }); toast(t('cf.purge_done'), 'ok'); }
            catch (err) { toast(err.message, 'err'); } finally { e.target.disabled = false; }
        });
    });
}

// ---------------------------------------------------------------- DNS
async function pageDns() {
    setActive('dns');
    main().innerHTML = `
    ${tabsHtml('protect', 'dns')}
    <div class="page-head"><div class="spacer"></div>
        <button class="btn primary" id="newzone">${icon('plus')}${t('dns.new_zone')}</button></div>
    <div class="card" id="zones">${t('common.loading')}</div>
    <div id="records"></div>`;

    document.getElementById('newzone').addEventListener('click', () => {
        const modal = openModal(`
            <div class="dialog-head"><h1>${t('dns.new_zone')}</h1><button class="btn ghost icon" data-close>${icon('x')}</button></div>
            <form id="zf">
                <div class="field"><label>${t('vhost.domain')}</label>
                    <input name="domain" required placeholder="example.com" class="mono">
                    <span class="hint">${t('dns.auto_records')}</span></div>
                <div class="dialog-foot"><button type="button" class="btn" data-close>${t('common.cancel')}</button>
                    <button class="btn primary">${t('common.create')}</button></div>
            </form>`);
        modal.querySelector('#zf').addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                await api('/dns/zones', { method: 'POST', body: Object.fromEntries(new FormData(e.target)) });
                modal.close();
                pageDns();
            } catch (err) { toast(err.message, 'err'); }
        });
    });

    const zones = await api('/dns/zones');
    document.getElementById('zones').innerHTML = zones.length ? `
        <table class="data"><thead><tr><th>${t('dns.zone')}</th><th class="hide-sm">Serial</th><th>DNSSEC</th><th></th></tr></thead><tbody>
        ${zones.map((z) => `<tr class="row-link" data-zone="${z.id}" data-domain="${esc(z.domain)}">
            <td class="mono">${esc(z.domain)}</td>
            <td class="mono hide-sm">${esc(z.serial)}</td>
            <td><span class="badge ${Number(z.dnssec_enabled) ? 'ok' : ''}">${Number(z.dnssec_enabled) ? 'on' : 'off'}</span></td>
            <td class="num"><button class="btn danger" data-delzone="${z.id}">${t('common.delete')}</button></td>
        </tr>`).join('')}</tbody></table>` : `<div class="empty">${t('nav.dns')}: 0</div>`;

    main().querySelectorAll('[data-delzone]').forEach((b) => b.addEventListener('click', async (e) => {
        e.stopPropagation();
        if (!confirm(t('common.confirm_delete'))) return;
        try { await api(`/dns/zones/${b.dataset.delzone}`, { method: 'DELETE' }); pageDns(); }
        catch (err) { toast(err.message, 'err'); }
    }));
    main().querySelectorAll('[data-zone]').forEach((tr) => tr.addEventListener('click', () =>
        dnsRecords(Number(tr.dataset.zone), tr.dataset.domain)));
}

async function dnsRecords(zoneId, domain) {
    const container = document.getElementById('records');
    container.innerHTML = `<div class="card mt">${t('common.loading')}</div>`;
    const records = await api(`/dns/zones/${zoneId}/records`);

    container.innerHTML = `
    <div class="card mt">
        <div class="page-head"><h2 class="mono">${esc(domain)}</h2></div>
        <table class="data"><thead><tr>
            <th>${t('dns.name')}</th><th>Tip</th><th>${t('dns.content')}</th><th class="hide-sm">TTL</th><th class="hide-sm">Prio</th><th></th>
        </tr></thead><tbody>
        ${records.map((r) => `<tr>
            <td class="mono">${esc(r.name)}</td>
            <td class="mono">${esc(r.type)}</td>
            <td class="mono" style="word-break:break-all">${esc(r.content)}</td>
            <td class="mono hide-sm">${r.ttl}</td>
            <td class="mono hide-sm">${r.prio ?? ''}</td>
            <td class="num"><button class="btn danger" data-delrec="${r.id}">${t('common.delete')}</button></td>
        </tr>`).join('')}</tbody></table>
        <form id="rf" class="mt">
            <div class="grid cols-4">
                <div class="field"><label>${t('dns.name')}</label><input name="name" placeholder="@" class="mono"></div>
                <div class="field"><label>Tip</label><select name="type" class="mono">
                    ${['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'].map((x) => `<option>${x}</option>`).join('')}</select></div>
                <div class="field"><label>${t('dns.content')}</label><input name="content" required class="mono"></div>
                <div class="field"><label>TTL / Prio</label><div class="grid cols-2">
                    <input name="ttl" value="3600" class="mono"><input name="prio" placeholder="—" class="mono"></div></div>
            </div>
            <button class="btn primary">${icon('plus')}${t('common.create')}</button>
        </form>
    </div>`;

    container.querySelector('#rf').addEventListener('submit', async (e) => {
        e.preventDefault();
        const body = Object.fromEntries(new FormData(e.target));
        if (!body.prio) delete body.prio;
        try {
            await api(`/dns/zones/${zoneId}/records`, { method: 'POST', body });
            dnsRecords(zoneId, domain);
        } catch (err) { toast(err.message, 'err'); }
    });
    container.querySelectorAll('[data-delrec]').forEach((b) => b.addEventListener('click', async () => {
        if (!confirm(t('common.confirm_delete'))) return;
        try { await api(`/dns/zones/${zoneId}/records/${b.dataset.delrec}`, { method: 'DELETE' }); dnsRecords(zoneId, domain); }
        catch (err) { toast(err.message, 'err'); }
    }));
}

// ---------------------------------------------------------------- mail
async function pageMail() {
    setActive('mail');
    main().innerHTML = `<div class="empty">${t('common.loading')}</div>`;

    const status = await api('/mail/status');
    if (!status.installed) {
        main().innerHTML = `
        <div class="card"><div class="empty">
            <p>${t('mail.not_installed')}</p>
            ${state.me.role === 'admin' ? `<button class="btn primary" id="setup">${t('mail.install')}</button>` : ''}
        </div></div>`;
        document.getElementById('setup')?.addEventListener('click', async () => {
            try {
                const r = await api('/mail/setup', { method: 'POST' });
                watchTask(r.task_id, 'mail.setup');
                toast(t('mail.installing'));
            } catch (err) { toast(err.message, 'err'); }
        });
        return;
    }

    const wm = status.webmail;
    main().innerHTML = `
    <div class="page-head"><div class="spacer"></div>
        ${wm?.hostname
            ? `<a class="btn" href="https://${esc(wm.hostname)}" target="_blank" rel="noopener">${icon('mail')}${t('mail.webmail')}</a>`
            : state.me.role === 'admin' ? `<button class="btn" id="wmsetup">${icon('mail')}${t('mail.webmail_install')}</button>` : ''}
        <button class="btn primary" id="newdom">${icon('plus')}${t('mail.new_domain')}</button></div>
    <div class="card" id="domains">${t('common.loading')}</div>
    <div id="detail"></div>`;

    document.getElementById('wmsetup')?.addEventListener('click', async () => {
        const hostname = prompt(t('mail.webmail_hostname'), `webmail.${location.hostname}`);
        if (!hostname) return;
        try {
            const r = await api('/mail/webmail', { method: 'POST', body: { hostname } });
            watchTask(r.task_id, 'mail.webmail_setup');
            toast(t('mail.webmail_installing'));
        } catch (err) { toast(err.message, 'err'); }
    });

    document.getElementById('newdom').addEventListener('click', () => {
        const modal = openModal(`
            <div class="dialog-head"><h1>${t('mail.new_domain')}</h1><button class="btn ghost icon" data-close>${icon('x')}</button></div>
            <form id="mf">
                <div class="field"><label>${t('vhost.domain')}</label>
                    <input name="domain" required placeholder="example.com" class="mono">
                    <span class="hint">${t('mail.dkim_auto')}</span></div>
                <div class="dialog-foot"><button type="button" class="btn" data-close>${t('common.cancel')}</button>
                    <button class="btn primary">${t('common.create')}</button></div>
            </form>`);
        modal.querySelector('#mf').addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                await api('/mail/domains', { method: 'POST', body: Object.fromEntries(new FormData(e.target)) });
                modal.close();
                pageMail();
            } catch (err) { toast(err.message, 'err'); }
        });
    });

    const domains = await api('/mail/domains');
    document.getElementById('domains').innerHTML = domains.length ? `
        <table class="data"><thead><tr><th>${t('vhost.domain')}</th><th class="hide-sm">DKIM</th><th></th></tr></thead><tbody>
        ${domains.map((d) => `<tr class="row-link" data-dom="${d.id}" data-name="${esc(d.domain)}">
            <td class="mono">${esc(d.domain)}</td>
            <td class="hide-sm"><span class="badge ${d.dkim_txt ? 'ok' : 'warn'}">${d.dkim_txt ? esc(d.dkim_selector) : '—'}</span></td>
            <td class="num"><button class="btn danger" data-deldom="${d.id}">${t('common.delete')}</button></td>
        </tr>`).join('')}</tbody></table>` : `<div class="empty">${t('nav.mail')}: 0</div>`;

    main().querySelectorAll('[data-deldom]').forEach((b) => b.addEventListener('click', async (e) => {
        e.stopPropagation();
        if (!confirm(t('mail.confirm_delete_domain'))) return;
        try { await api(`/mail/domains/${b.dataset.deldom}`, { method: 'DELETE' }); pageMail(); }
        catch (err) { toast(err.message, 'err'); }
    }));
    main().querySelectorAll('[data-dom]').forEach((tr) => tr.addEventListener('click', () =>
        mailDomainDetail(Number(tr.dataset.dom), tr.dataset.name, domains.find((d) => d.id == tr.dataset.dom))));
}

async function mailDomainDetail(domainId, domainName, domain) {
    const container = document.getElementById('detail');
    container.innerHTML = `<div class="card mt">${t('common.loading')}</div>`;
    const [mailboxes, aliases] = await Promise.all([
        api(`/mail/domains/${domainId}/mailboxes`),
        api(`/mail/domains/${domainId}/aliases`),
    ]);

    container.innerHTML = `
    <div class="grid cols-2 mt">
        <div class="card">
            <h2>${t('mail.mailboxes')} — <span class="mono">${esc(domainName)}</span></h2>
            ${mailboxes.length ? `<table class="data"><tbody>
                ${mailboxes.map((m) => `<tr>
                    <td class="mono">${esc(m.local_part)}@${esc(domainName)}</td>
                    <td class="mono num">${fmtBytes(m.quota_bytes)}</td>
                    <td class="num"><button class="btn danger" data-delmb="${m.id}">${t('common.delete')}</button></td>
                </tr>`).join('')}</tbody></table>` : `<div class="empty">0</div>`}
            <form id="mbf" class="mt">
                <div class="grid cols-2">
                    <div class="field"><label>${t('mail.local_part')}</label>
                        <input name="local_part" required pattern="[a-z0-9][a-z0-9._\\-]{0,63}" class="mono"></div>
                    <div class="field"><label>${t('auth.password')}</label>
                        <input name="password" type="password" required minlength="10"></div>
                </div>
                <button class="btn primary">${icon('plus')}${t('common.create')}</button>
            </form>
        </div>
        <div class="card">
            <h2>${t('mail.aliases')}</h2>
            ${aliases.length ? `<table class="data"><tbody>
                ${aliases.map((a) => `<tr>
                    <td class="mono">${esc(a.source)}</td>
                    <td class="mono">→ ${esc(a.destination)}</td>
                    <td class="num"><button class="btn danger" data-delal="${a.id}">${t('common.delete')}</button></td>
                </tr>`).join('')}</tbody></table>` : `<div class="empty">0</div>`}
            <form id="alf" class="mt">
                <div class="grid cols-2">
                    <div class="field"><label>${t('mail.alias_source')}</label>
                        <input name="source" required pattern="[a-z0-9][a-z0-9._\\-]{0,63}" class="mono" placeholder="info"></div>
                    <div class="field"><label>${t('mail.alias_destination')}</label>
                        <input name="destination" type="email" required class="mono"></div>
                </div>
                <button class="btn primary">${icon('plus')}${t('common.create')}</button>
            </form>
            ${domain?.dkim_txt ? `
            <h2 class="mt">DKIM (${esc(domain.dkim_selector)}._domainkey TXT)</h2>
            <div class="task-output">${esc(domain.dkim_txt)}</div>` : ''}
        </div>
    </div>`;

    container.querySelector('#mbf').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            await api(`/mail/domains/${domainId}/mailboxes`, { method: 'POST', body: Object.fromEntries(new FormData(e.target)) });
            mailDomainDetail(domainId, domainName, domain);
        } catch (err) { toast(err.message, 'err'); }
    });
    container.querySelector('#alf').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            await api(`/mail/domains/${domainId}/aliases`, { method: 'POST', body: Object.fromEntries(new FormData(e.target)) });
            mailDomainDetail(domainId, domainName, domain);
        } catch (err) { toast(err.message, 'err'); }
    });
    container.querySelectorAll('[data-delmb]').forEach((b) => b.addEventListener('click', async () => {
        if (!confirm(t('common.confirm_delete'))) return;
        try { await api(`/mail/domains/${domainId}/mailboxes/${b.dataset.delmb}`, { method: 'DELETE' }); mailDomainDetail(domainId, domainName, domain); }
        catch (err) { toast(err.message, 'err'); }
    }));
    container.querySelectorAll('[data-delal]').forEach((b) => b.addEventListener('click', async () => {
        if (!confirm(t('common.confirm_delete'))) return;
        try { await api(`/mail/domains/${domainId}/aliases/${b.dataset.delal}`, { method: 'DELETE' }); mailDomainDetail(domainId, domainName, domain); }
        catch (err) { toast(err.message, 'err'); }
    }));
}

// ---------------------------------------------------------------- backups
async function pageBackups() {
    setActive('backups');
    main().innerHTML = `<div class="card">${t('common.loading')}</div>`;
    const backups = await api('/backups');
    main().querySelector('.card').innerHTML = backups.length ? `
        <table class="data"><thead><tr>
            <th>#</th><th>Path</th><th class="hide-sm">${t('backup.size')}</th><th>Status</th><th class="hide-sm">Kreirano</th><th></th>
        </tr></thead><tbody>
        ${backups.map((b) => `<tr>
            <td class="mono">${b.id}</td>
            <td class="mono" style="word-break:break-all">${esc(b.path || '—')}</td>
            <td class="mono hide-sm">${fmtBytes(b.size_bytes)}</td>
            <td>${statusBadge(b.status)}</td>
            <td class="hide-sm">${fmtDate(b.created_at)}</td>
            <td class="num">
                ${b.status === 'done' ? `<button class="btn" data-restore="${b.id}">${t('backup.restore')}</button>` : ''}
                <button class="btn danger" data-del="${b.id}">${t('common.delete')}</button>
            </td></tr>`).join('')}</tbody></table>` : `<div class="empty">${t('nav.backups')}: 0</div>`;

    main().querySelectorAll('[data-restore]').forEach((b) => b.addEventListener('click', () => {
        const backup = backups.find((x) => String(x.id) === b.dataset.restore);
        const manifest = backup.manifest ? JSON.parse(backup.manifest) : { databases: [] };
        const modal = openModal(`
            <div class="dialog-head"><h1>${t('backup.restore')}</h1><button class="btn ghost icon" data-close>${icon('x')}</button></div>
            <form id="rf">
                <div class="field"><label>${t('backup.mode')}</label>
                    <select name="mode">
                        <option value="files">${t('backup.mode_files')}</option>
                        ${manifest.databases.map((d) => `<option value="db:${esc(d)}">${t('backup.mode_db')}: ${esc(d)}</option>`).join('')}
                    </select></div>
                <div class="alert err">${t('backup.restore_warning')}</div>
                <div class="dialog-foot"><button type="button" class="btn" data-close>${t('common.cancel')}</button>
                    <button class="btn primary">${t('backup.restore')}</button></div>
            </form>`);
        modal.querySelector('#rf').addEventListener('submit', async (e) => {
            e.preventDefault();
            const value = new FormData(e.target).get('mode');
            const body = value.startsWith('db:') ? { mode: 'db', db_name: value.slice(3) } : { mode: 'files' };
            try {
                const r = await api(`/backups/${backup.id}/restore`, { method: 'POST', body });
                modal.close();
                watchTask(r.task_id, `restore #${backup.id}`);
            } catch (err) { toast(err.message, 'err'); }
        });
    }));
    main().querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', async () => {
        if (!confirm(t('common.confirm_delete'))) return;
        try { await api(`/backups/${b.dataset.del}`, { method: 'DELETE' }); pageBackups(); }
        catch (err) { toast(err.message, 'err'); }
    }));
}

// ---------------------------------------------------------------- docker
async function pageDocker() {
    setActive('docker');
    main().innerHTML = `
    <div class="page-head"><div class="spacer"></div>
        <button class="btn primary" id="newc">${icon('plus')}${t('docker.new')}</button></div>
    <div class="card" id="list">${t('common.loading')}</div>`;

    document.getElementById('newc').addEventListener('click', () => {
        const modal = openModal(`
            <div class="dialog-head"><h1>${t('docker.new')}</h1><button class="btn ghost icon" data-close>${icon('x')}</button></div>
            <form id="cf">
                <div class="grid cols-2">
                    <div class="field"><label>${t('docker.name')}</label><input name="name" required pattern="[a-z0-9][a-z0-9_\\-]{0,40}" class="mono"></div>
                    <div class="field"><label>Image</label><input name="image" required class="mono" placeholder="nginx:alpine"></div>
                </div>
                <div class="grid cols-2">
                    <div class="field"><label>${t('docker.host_port')} (127.0.0.1)</label><input name="host_port" type="number" min="1024" max="65535" class="mono"></div>
                    <div class="field"><label>${t('docker.container_port')}</label><input name="container_port" type="number" min="1" max="65535" class="mono"></div>
                </div>
                <div class="field"><label>RAM limit (MB)</label><input name="memory_mb" type="number" value="256" min="16" max="16384" class="mono"></div>
                <div class="dialog-foot"><button type="button" class="btn" data-close>${t('common.cancel')}</button>
                    <button class="btn primary">${t('common.create')}</button></div>
            </form>`);
        modal.querySelector('#cf').addEventListener('submit', async (e) => {
            e.preventDefault();
            const f = Object.fromEntries(new FormData(e.target));
            const body = {
                name: f.name,
                image: f.image,
                memory_bytes: Number(f.memory_mb) * 1048576,
                ports: f.host_port ? [{ host: Number(f.host_port), container: Number(f.container_port || 80) }] : [],
            };
            try {
                const r = await api('/docker', { method: 'POST', body });
                modal.close();
                watchTask(r.task_id, `docker ${f.name}`);
            } catch (err) { toast(err.message, 'err'); }
        });
    });

    const containers = await api('/docker');
    document.getElementById('list').innerHTML = containers.length ? `
        <table class="data"><thead><tr>
            <th>${t('docker.name')}</th><th>Image</th><th>Status</th><th class="hide-sm">Proxy</th><th></th>
        </tr></thead><tbody>
        ${containers.map((c) => `<tr>
            <td class="mono">${esc(c.name)}</td>
            <td class="mono">${esc(c.image)}</td>
            <td><span class="badge ${c.state === 'running' ? 'ok' : c.state === 'exited' ? 'err' : ''}">${esc(c.state)}</span></td>
            <td class="mono hide-sm">${c.proxy_port ? ':' + c.proxy_port : '—'}</td>
            <td class="num">
                <button class="btn ghost" data-act="restart" data-id="${c.id}">↻</button>
                <button class="btn ghost" data-act="${c.state === 'running' ? 'stop' : 'start'}" data-id="${c.id}">${c.state === 'running' ? '⏸' : '▶'}</button>
                <button class="btn ghost" data-logs="${c.id}">${t('docker.logs')}</button>
                <button class="btn danger" data-del="${c.id}">${t('common.delete')}</button>
            </td></tr>`).join('')}</tbody></table>` : `<div class="empty">${t('nav.docker')}: 0</div>`;

    main().querySelectorAll('[data-act]').forEach((b) => b.addEventListener('click', async () => {
        try { await api(`/docker/${b.dataset.id}/action`, { method: 'POST', body: { action: b.dataset.act } }); pageDocker(); }
        catch (err) { toast(err.message, 'err'); }
    }));
    main().querySelectorAll('[data-logs]').forEach((b) => b.addEventListener('click', async () => {
        try {
            const r = await api(`/docker/${b.dataset.logs}/logs`);
            openModal(`<div class="dialog-head"><h1>${t('docker.logs')}</h1>
                <button class="btn ghost icon" data-close>${icon('x')}</button></div>
                <div class="task-output">${esc(r.logs ?? '')}</div>`, { wide: true });
        } catch (err) { toast(err.message, 'err'); }
    }));
    main().querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', async () => {
        if (!confirm(t('common.confirm_delete'))) return;
        try { await api(`/docker/${b.dataset.del}`, { method: 'DELETE' }); pageDocker(); }
        catch (err) { toast(err.message, 'err'); }
    }));
}

// ---------------------------------------------------------------- users (admin/reseller) + white-label
async function pageUsers() {
    setActive('users');
    main().innerHTML = `
    <div class="page-head"><div class="spacer"></div>
        <button class="btn" id="brand">${t('users.branding')}</button>
        <button class="btn primary" id="newu">${icon('plus')}${t('users.new')}</button></div>
    <div class="card" id="list">${t('common.loading')}</div>
    <div class="card mt"><h2>${t('users.plans')}</h2><div id="plans">${t('common.loading')}</div></div>`;

    document.getElementById('newu').addEventListener('click', () => userModal());
    document.getElementById('brand').addEventListener('click', brandingModal);

    const [users, plans] = await Promise.all([api('/users'), api('/plans')]);
    document.getElementById('list').innerHTML = users.length ? `
        <table class="data"><thead><tr><th>${t('auth.email')}</th><th>Rola</th><th>Status</th><th class="hide-sm">Zadnja prijava</th><th></th></tr></thead><tbody>
        ${users.map((u) => `<tr>
            <td class="mono">${esc(u.email)}</td>
            <td><span class="badge ${u.role === 'admin' ? 'err' : u.role === 'reseller' ? 'warn' : ''}">${esc(u.role)}</span></td>
            <td>${statusBadge(u.status === 'active' ? 'active' : 'suspended')}</td>
            <td class="hide-sm">${fmtDate(u.last_login_at)}</td>
            <td class="num">
                <button class="btn ghost" data-toggle="${u.id}" data-status="${u.status}">${u.status === 'active' ? t('bulk.suspend') : t('bulk.unsuspend')}</button>
                <button class="btn ghost" data-edit="${u.id}">${t('common.edit')}</button>
                <button class="btn danger" data-del="${u.id}">${t('common.delete')}</button>
            </td></tr>`).join('')}</tbody></table>` : `<div class="empty">0</div>`;

    const canEditPlan = (p) => state.me.role === 'admin' || p.owner_user_id != null;
    document.getElementById('plans').innerHTML = `
        <table class="data"><tbody>
        ${plans.map((p) => `<tr>
            <td class="mono">${esc(p.name)}</td>
            <td class="mono">${fmtBytes(p.disk_bytes)} · ${p.max_domains} domena · ${p.max_mailboxes} mail · ${p.max_databases} baza</td>
            <td class="mono">${(JSON.parse(p.php_versions || '[]')).join(', ')}</td>
            <td class="num">${canEditPlan(p) ? `
                <button class="btn ghost" data-pedit="${p.id}">${t('common.edit')}</button>
                <button class="btn danger" data-pdel="${p.id}">${t('common.delete')}</button>` : ''}</td>
        </tr>`).join('') || `<tr><td><div class="empty">0</div></td></tr>`}
        </tbody></table>
        <button class="btn mt" id="newplan">${icon('plus')}${t('users.new_plan')}</button>`;
    document.getElementById('newplan').addEventListener('click', () => planModal());
    main().querySelectorAll('[data-pedit]').forEach((b) => b.addEventListener('click', () => planModal(plans.find((p) => String(p.id) === b.dataset.pedit))));
    main().querySelectorAll('[data-pdel]').forEach((b) => b.addEventListener('click', async () => {
        if (!confirm(t('common.confirm_delete'))) return;
        try { await api(`/plans/${b.dataset.pdel}`, { method: 'DELETE' }); pageUsers(); }
        catch (err) { toast(err.message, 'err'); }
    }));

    main().querySelectorAll('[data-toggle]').forEach((b) => b.addEventListener('click', async () => {
        try { await api(`/users/${b.dataset.toggle}/status`, { method: 'PUT', body: { status: b.dataset.status === 'active' ? 'suspended' : 'active' } }); pageUsers(); }
        catch (err) { toast(err.message, 'err'); }
    }));
    main().querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () => userModal(users.find((u) => String(u.id) === b.dataset.edit))));
    main().querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', async () => {
        if (!confirm(t('common.confirm_delete'))) return;
        try { await api(`/users/${b.dataset.del}`, { method: 'DELETE' }); pageUsers(); }
        catch (err) { toast(err.message, 'err'); }
    }));
}

function userModal(user = null) {
    const isAdmin = state.me.role === 'admin';
    const edit = user != null;
    const modal = openModal(`
        <div class="dialog-head"><h1>${edit ? t('users.edit') : t('users.new')}</h1><button class="btn ghost icon" data-close>${icon('x')}</button></div>
        <form id="uf">
            <div class="field"><label>${t('auth.email')}</label><input name="email" type="email" required class="mono" value="${edit ? esc(user.email) : ''}"></div>
            <div class="field"><label>${t('auth.password')}</label><input name="password" type="password" ${edit ? '' : 'required'} minlength="12" placeholder="${edit ? t('users.password_keep') : ''}"></div>
            <div class="field"><label>Rola</label><select name="role" ${edit && !isAdmin ? 'disabled' : ''}>
                <option value="client" ${edit && user.role === 'client' ? 'selected' : ''}>client</option>
                ${isAdmin ? `<option value="reseller" ${edit && user.role === 'reseller' ? 'selected' : ''}>reseller</option><option value="admin" ${edit && user.role === 'admin' ? 'selected' : ''}>admin</option>` : ''}
            </select></div>
            <div class="dialog-foot"><button type="button" class="btn" data-close>${t('common.cancel')}</button>
                <button class="btn primary">${edit ? t('common.save') : t('common.create')}</button></div>
        </form>`);
    modal.querySelector('#uf').addEventListener('submit', async (e) => {
        e.preventDefault();
        const body = Object.fromEntries(new FormData(e.target));
        if (edit && !body.password) delete body.password; // ne mijenjaj lozinku ako je prazna
        try {
            if (edit) await api(`/users/${user.id}`, { method: 'PUT', body });
            else await api('/users', { method: 'POST', body });
            modal.close(); pageUsers();
        } catch (err) { toast(err.message, 'err'); }
    });
}

function planModal(plan = null) {
    const edit = plan != null;
    const sel = edit ? JSON.parse(plan.php_versions || '[]') : ['8.4', '8.5'];
    const v = (def, key) => edit ? plan[key] : def;
    const modal = openModal(`
        <div class="dialog-head"><h1>${edit ? t('users.edit_plan') : t('users.new_plan')}</h1><button class="btn ghost icon" data-close>${icon('x')}</button></div>
        <form id="pf">
            <div class="field"><label>Naziv</label><input name="name" required value="${edit ? esc(plan.name) : ''}"></div>
            <div class="grid cols-2">
                <div class="field"><label>Disk (GB)</label><input name="disk_gb" type="number" value="${edit ? Math.round(plan.disk_bytes / 1073741824) : 10}" class="mono"></div>
                <div class="field"><label>Max domena</label><input name="max_domains" type="number" value="${v(5, 'max_domains')}" class="mono"></div>
                <div class="field"><label>Max mailboxa</label><input name="max_mailboxes" type="number" value="${v(10, 'max_mailboxes')}" class="mono"></div>
                <div class="field"><label>Max baza</label><input name="max_databases" type="number" value="${v(5, 'max_databases')}" class="mono"></div>
            </div>
            <div class="field"><label>PHP verzije</label>
                <div class="checkrow">${['8.1', '8.2', '8.3', '8.4', '8.5'].map((ver) =>
                    `<label class="chk"><input type="checkbox" name="php" value="${ver}" ${sel.includes(ver) ? 'checked' : ''}> ${ver}</label>`).join('')}</div></div>
            <div class="dialog-foot"><button type="button" class="btn" data-close>${t('common.cancel')}</button>
                <button class="btn primary">${edit ? t('common.save') : t('common.create')}</button></div>
        </form>`);
    modal.querySelector('#pf').addEventListener('submit', async (e) => {
        e.preventDefault();
        const f = Object.fromEntries(new FormData(e.target));
        const php = [...e.target.querySelectorAll('input[name="php"]:checked')].map((c) => c.value);
        if (php.length === 0) return toast(t('plan.pick_php') || 'Odaberi bar jednu PHP verziju', 'err');
        const body = {
            name: f.name,
            disk_bytes: Number(f.disk_gb) * 1073741824,
            max_domains: Number(f.max_domains),
            max_mailboxes: Number(f.max_mailboxes),
            max_databases: Number(f.max_databases),
            php_versions: php,
        };
        try {
            if (edit) await api(`/plans/${plan.id}`, { method: 'PUT', body });
            else await api('/plans', { method: 'POST', body });
            modal.close(); pageUsers();
        } catch (err) { toast(err.message, 'err'); }
    });
}

function brandingModal() {
    const b = state.branding ?? {};
    const modal = openModal(`
        <div class="dialog-head"><h1>${t('users.branding')}</h1><button class="btn ghost icon" data-close>${icon('x')}</button></div>
        <form id="bf">
            <div class="field"><label>${t('brand.name')}</label><input name="panel_name" value="${esc(b.panel_name ?? 'ForgePanel')}"></div>
            <div class="field"><label>${t('brand.accent')}</label><input name="accent" type="color" value="${esc(b.accent ?? '#f59e0b')}" style="height:38px"></div>
            <div class="field"><label>${t('brand.host')}</label><input name="panel_host" class="mono" placeholder="panel.mojadomena.hr" value="${esc(b.panel_host ?? '')}">
                <span class="hint">${t('brand.host_hint')}</span></div>
            <div class="field"><label>Logo URL (https)</label><input name="logo_url" class="mono" value="${esc(b.logo_url ?? '')}"></div>
            <div class="dialog-foot"><button type="button" class="btn" data-close>${t('common.cancel')}</button>
                <button class="btn primary">${t('common.save')}</button></div>
        </form>`);
    modal.querySelector('#bf').addEventListener('submit', async (e) => {
        e.preventDefault();
        const f = Object.fromEntries(new FormData(e.target));
        if (!f.logo_url) delete f.logo_url;
        if (!f.panel_host) delete f.panel_host;
        try {
            const r = await api('/branding', { method: 'PUT', body: f });
            state.branding = r;
            document.documentElement.style.setProperty('--accent', r.accent);
            modal.close();
            toast(t('brand.saved'));
        } catch (err) { toast(err.message, 'err'); }
    });
}

// ---------------------------------------------------------------- AI asistent → Forge AI drawer (⌘J)
function pageAssistant() {
    location.hash = '#/dashboard';
    toggleAiDrawer(true);
}

// ---------------------------------------------------------------- config time-machine (admin)
async function pageConfig() {
    setActive('config');
    main().innerHTML = `${tabsHtml('server', 'config')}
        <div class="card">${t('config.intro')}</div>
        <div class="card mt" id="hist">${t('common.loading')}</div>`;
    const { history } = await api('/config-history');
    document.getElementById('hist').innerHTML = history.length ? `
        <table class="data"><thead><tr><th>${t('config.when')}</th><th>${t('config.what')}</th><th>${t('config.who')}</th><th></th></tr></thead><tbody>
        ${history.map((h) => `<tr>
            <td class="mono">${fmtDate(h.date)}</td>
            <td>${esc(h.message)}</td>
            <td class="mono">${esc(h.changed_by)}</td>
            <td class="num">
                <button class="btn ghost" data-diff="${esc(h.hash)}">diff</button>
                <button class="btn" data-restore="${esc(h.hash)}">${t('config.restore')}</button>
            </td></tr>`).join('')}</tbody></table>` : `<div class="empty">${t('config.empty')}</div>`;

    main().querySelectorAll('[data-diff]').forEach((b) => b.addEventListener('click', async () => {
        const r = await api(`/config-history/${b.dataset.diff}/diff`);
        openModal(`<div class="dialog-head"><h1 class="mono">${esc(b.dataset.diff.slice(0, 10))}</h1>
            <button class="btn ghost icon" data-close>${icon('x')}</button></div>
            <div class="task-output">${esc(r.diff || 'nema promjena')}</div>`, { wide: true });
    }));
    main().querySelectorAll('[data-restore]').forEach((b) => b.addEventListener('click', async () => {
        if (!confirm(t('config.confirm_restore'))) return;
        try { await api(`/config-history/${b.dataset.restore}/restore`, { method: 'POST' }); toast(t('config.restored')); }
        catch (err) { toast(err.message, 'err'); }
    }));
}

// ---------------------------------------------------------------- security
async function pageSecurity() {
    setActive('security');
    main().innerHTML = `
    ${tabsHtml('protect', 'security')}
    <div class="card"><h2>${t('security.scan_vhost')}</h2><div id="scanbox">${t('common.loading')}</div></div>
    <div class="card mt"><h2>${t('security.quarantine')}</h2><div id="quar">${t('common.loading')}</div></div>
    <div class="card mt"><h2>${t('deliv.title')}</h2><div id="deliv"></div></div>
    <div class="card mt"><h2>${t('security.history')}</h2><div id="scans">${t('common.loading')}</div></div>`;

    deliverabilityPanel(document.getElementById('deliv'));
    const vhosts = await api('/vhosts');
    document.getElementById('scanbox').innerHTML = `
        <div class="field" style="max-width:480px">
            <select id="sv" class="mono">${vhosts.map((v) => `<option value="${v.id}">${esc(v.domain)}</option>`).join('')}</select>
        </div>
        <label style="display:flex;gap:8px;align-items:center;margin-bottom:10px">
            <input type="checkbox" id="autoq"> ${t('security.auto_quarantine')}</label>
        <button class="btn primary" id="runscan">${icon('shield')}${t('security.run_scan')}</button>`;
    document.getElementById('runscan').addEventListener('click', async () => {
        const id = document.getElementById('sv').value;
        try {
            const r = await api(`/vhosts/${id}/security/scan`, { method: 'POST', body: { auto_quarantine: document.getElementById('autoq').checked } });
            watchTask(r.task_id, `malware.scan`);
        } catch (err) { toast(err.message, 'err'); }
    });

    const [quar, scans] = await Promise.all([api('/security/quarantine'), api('/security/scans')]);
    document.getElementById('quar').innerHTML = quar.length ? `
        <table class="data"><thead><tr><th>${t('vhost.domain')}</th><th>Path</th><th>Signatura</th><th></th></tr></thead><tbody>
        ${quar.map((q) => `<tr>
            <td class="mono">${esc(q.domain)}</td>
            <td class="mono" style="word-break:break-all">${esc(q.path)}</td>
            <td class="mono"><span class="badge err">${esc(q.signature)}</span></td>
            <td class="num">
                <button class="btn" data-restore="${q.id}">${t('security.restore')}</button>
                <button class="btn danger" data-purge="${q.id}">${t('common.delete')}</button>
            </td></tr>`).join('')}</tbody></table>` : `<div class="empty">${t('security.clean')}</div>`;

    document.getElementById('scans').innerHTML = scans.length ? `
        <table class="data"><tbody>
        ${scans.map((s) => `<tr>
            <td class="mono">${esc(s.domain ?? '—')}</td>
            <td>${statusBadge(s.status)}</td>
            <td class="mono">${s.files_scanned} fileova</td>
            <td><span class="badge ${Number(s.threats_found) ? 'err' : 'ok'}">${s.threats_found} prijetnji</span></td>
            <td class="hide-sm">${fmtDate(s.started_at)}</td>
        </tr>`).join('')}</tbody></table>` : `<div class="empty">0</div>`;

    main().querySelectorAll('[data-restore]').forEach((b) => b.addEventListener('click', async () => {
        if (!confirm(t('security.confirm_restore'))) return;
        try { await api(`/security/quarantine/${b.dataset.restore}/restore`, { method: 'POST' }); pageSecurity(); }
        catch (err) { toast(err.message, 'err'); }
    }));
    main().querySelectorAll('[data-purge]').forEach((b) => b.addEventListener('click', async () => {
        if (!confirm(t('common.confirm_delete'))) return;
        try { await api(`/security/quarantine/${b.dataset.purge}/delete`, { method: 'POST' }); pageSecurity(); }
        catch (err) { toast(err.message, 'err'); }
    }));
}

// ---------------------------------------------------------------- deliverability
async function deliverabilityPanel(container) {
    container.innerHTML = `
    <div class="grid cols-2">
        <div class="field"><label>${t('deliv.validate_domain')}</label>
            <div style="display:flex;gap:8px"><input id="dvd" class="mono" placeholder="example.com">
                <button class="btn" id="dvb">${t('deliv.check')}</button></div></div>
        <div class="field"><label>${t('deliv.rbl_check')}</label>
            <button class="btn" id="drb">${t('deliv.rbl_server')}</button></div>
    </div>
    <div id="dresult"></div>`;

    container.querySelector('#dvb').addEventListener('click', async () => {
        const domain = container.querySelector('#dvd').value.trim();
        if (!domain) return;
        try {
            const r = await api('/deliverability/validate', { method: 'POST', body: { domain } });
            const v = r.validation;
            container.querySelector('#dresult').innerHTML = `
                <table class="data mt"><tbody>
                    <tr><td>SPF</td><td><span class="badge ${v.spf.found ? (v.spf.issue ? 'warn' : 'ok') : 'err'}">${v.spf.found ? (v.spf.issue ?? 'OK') : 'nema'}</span></td><td class="mono">${esc(v.spf.record ?? '')}</td></tr>
                    <tr><td>DKIM</td><td><span class="badge ${v.dkim.found ? 'ok' : 'err'}">${v.dkim.found ? 'OK' : 'nema'}</span></td><td></td></tr>
                    <tr><td>DMARC</td><td><span class="badge ${v.dmarc.found ? 'ok' : 'err'}">${v.dmarc.found ? 'p=' + esc(v.dmarc.policy) : 'nema'}</span></td><td class="mono">${esc(v.dmarc.record ?? '')}</td></tr>
                </tbody></table>`;
        } catch (err) { toast(err.message, 'err'); }
    });
    container.querySelector('#drb').addEventListener('click', async () => {
        try {
            const r = await api('/deliverability/rbl');
            container.querySelector('#dresult').innerHTML = `<div class="alert ${r.listed_on.length ? 'err' : 'ok'} mt">
                ${r.ip}: ${r.listed_on.length ? t('deliv.listed') + ': ' + r.listed_on.join(', ') : t('deliv.clean') + ' (' + r.checked + ' lista)'}</div>`;
        } catch (err) { toast(err.message, 'err'); }
    });
}

// ---------------------------------------------------------------- firewall (admin)
async function pageFirewall() {
    setActive('firewall');
    main().innerHTML = `
    ${tabsHtml('protect', 'firewall')}
    <div class="card"><h2>ufw</h2><div id="ufw">${t('common.loading')}</div></div>
    <div class="card mt"><h2>fail2ban</h2><div id="f2b">${t('common.loading')}</div></div>`;

    try {
        const rules = await api('/firewall/rules');
        document.getElementById('ufw').innerHTML = `
            <div class="task-output">${esc((rules.rules || []).join('\n') || 'nema pravila')}</div>
            <form id="uf" class="mt"><div class="grid cols-4">
                <div class="field"><label>Port</label><input name="port" type="number" required class="mono"></div>
                <div class="field"><label>Proto</label><select name="proto" class="mono"><option>tcp</option><option>udp</option></select></div>
                <div class="field"><label>Od (CIDR, opc.)</label><input name="from" class="mono" placeholder="any"></div>
                <div class="field"><label>&nbsp;</label><button class="btn primary">${t('firewall.allow')}</button></div>
            </div></form>`;
        document.getElementById('uf').addEventListener('submit', async (e) => {
            e.preventDefault();
            const f = Object.fromEntries(new FormData(e.target));
            if (!f.from) delete f.from;
            try { await api('/firewall/rules', { method: 'POST', body: { ...f, port: Number(f.port) } }); pageFirewall(); }
            catch (err) { toast(err.message, 'err'); }
        });

        const data = await api('/firewall/jails');
        document.getElementById('f2b').innerHTML = (data.jails || []).length ? `
            <table class="data"><thead><tr><th>Jail</th><th>Banano</th><th class="hide-sm">Ukupno</th><th>IP-ovi</th></tr></thead><tbody>
            ${data.jails.map((j) => `<tr>
                <td class="mono">${esc(j.jail)}</td>
                <td class="mono"><span class="badge ${j.banned ? 'warn' : 'ok'}">${j.banned}</span></td>
                <td class="mono hide-sm">${j.total}</td>
                <td class="mono">${j.ips.map((ip) => `${esc(ip)} <button class="btn ghost" data-unban="${esc(j.jail)}|${esc(ip)}">✕</button>`).join(' ') || '—'}</td>
            </tr>`).join('')}</tbody></table>` : `<div class="empty">nema jailova</div>`;
        main().querySelectorAll('[data-unban]').forEach((b) => b.addEventListener('click', async () => {
            const [jail, ip] = b.dataset.unban.split('|');
            try { await api('/firewall/unban', { method: 'POST', body: { jail, ip } }); pageFirewall(); }
            catch (err) { toast(err.message, 'err'); }
        }));
    } catch (err) {
        main().querySelector('.content')?.insertAdjacentHTML?.('beforeend', '');
        document.getElementById('ufw').innerHTML = `<div class="alert err">${esc(err.message)}</div>`;
    }
}

// ---------------------------------------------------------------- updates (admin)
async function pageUpdates() {
    setActive('updates');
    main().innerHTML = `
    ${tabsHtml('server', 'updates')}
    <div class="page-head"><div class="spacer"></div>
        <button class="btn" id="scan">${icon('refresh')}${t('updates.scan')}</button></div>
    <div class="card" id="comps">${t('common.loading')}</div>
    <div class="card mt"><h2>${t('updates.history')}</h2><div id="hist">${t('common.loading')}</div></div>`;

    document.getElementById('scan').addEventListener('click', async () => {
        try {
            const r = await api('/updates/scan', { method: 'POST' });
            watchTask(r.task_id, 'updates.scan');
        } catch (err) { toast(err.message, 'err'); }
    });

    const [comps, hist] = await Promise.all([api('/updates'), api('/updates/history')]);

    document.getElementById('comps').innerHTML = comps.length ? `
        <table class="data"><thead><tr>
            <th>${t('updates.component')}</th><th>${t('updates.current')}</th><th>${t('updates.available')}</th>
            <th class="hide-sm">Suite</th><th>${t('updates.policy')}</th><th></th>
        </tr></thead><tbody>
        ${comps.map((c) => `<tr>
            <td class="mono">${esc(c.name)}${c.status === 'frozen' ? ' <span class="badge err">frozen</span>' : ''}</td>
            <td class="mono">${esc(c.current_version ?? '—')}</td>
            <td class="mono">${c.available_version
                ? `<span class="badge ${c.security_update ? 'err' : 'warn'}">${esc(c.available_version)}${c.security_update ? ' · security' : ''}</span>`
                : '<span class="badge ok">aktualno</span>'}</td>
            <td class="mono hide-sm">${esc(c.repo_suite ?? '')}</td>
            <td><select class="mono" data-policy="${esc(c.name)}">
                ${['manual', 'auto_all', 'auto_security_only', 'frozen'].map((m) =>
                    `<option value="${m}" ${c.mode === m ? 'selected' : ''}>${t('updates.mode_' + m)}</option>`).join('')}
            </select></td>
            <td class="num">${c.available_version && c.status !== 'frozen'
                ? `<button class="btn primary" data-apply="${esc(c.name)}">${t('updates.apply')}</button>` : ''}</td>
        </tr>`).join('')}</tbody></table>` : `<div class="empty">${t('updates.run_scan')}</div>`;

    document.getElementById('hist').innerHTML = hist.length ? `
        <table class="data"><tbody>
        ${hist.map((h) => `<tr>
            <td class="mono">${esc(h.name)}</td>
            <td class="mono">${esc(h.from_version)} → ${esc(h.to_version)}</td>
            <td>${statusBadge(h.status === 'rolled_back' ? 'failed' : h.status)}${h.status === 'rolled_back' ? ' <span class="badge warn">rollback</span>' : ''}</td>
            <td class="hide-sm">${fmtDate(h.created_at)}</td>
        </tr>`).join('')}</tbody></table>` : `<div class="empty">0</div>`;

    main().querySelectorAll('[data-apply]').forEach((b) => b.addEventListener('click', async () => {
        try {
            const r = await api(`/updates/${b.dataset.apply}/apply`, { method: 'POST' });
            watchTask(r.task_id, `update ${b.dataset.apply}`);
        } catch (err) { toast(err.message, 'err'); }
    }));
    main().querySelectorAll('[data-policy]').forEach((s) => s.addEventListener('change', async () => {
        try {
            await api(`/updates/${s.dataset.policy}/policy`, { method: 'PUT', body: { mode: s.value } });
            toast(`${s.dataset.policy}: ${t('updates.mode_' + s.value)}`);
        } catch (err) { toast(err.message, 'err'); pageUpdates(); }
    }));
}

// ---------------------------------------------------------------- profil (2FA + sigurnosni ključevi)
async function pageProfile() {
    setActive('profile');
    state.me = await api('/auth/me');
    main().innerHTML = `
    <div class="card">
        <h2>${t('profile.totp')}</h2>
        <div id="totpbox">${state.me.twofa_enabled
            ? `<span class="badge ok">${t('profile.enabled')}</span>`
            : `<p>${t('profile.totp_hint')}</p><button class="btn primary" id="totpsetup">${icon('lock')}${t('profile.totp_setup')}</button>`}</div>
    </div>
    <div class="card mt">
        <h2>${t('profile.webauthn')}</h2>
        <p>${t('profile.webauthn_hint')}</p>
        <div id="keys">${t('common.loading')}</div>
        <form id="addkey" style="display:flex;margin-top:12px;gap:8px">
            <input name="label" placeholder="${t('profile.key_label')}" maxlength="64" required style="flex:1">
            <button class="btn primary">${icon('key')}${t('profile.add_key')}</button>
        </form>
    </div>`;

    document.getElementById('totpsetup')?.addEventListener('click', async () => {
        const box = document.getElementById('totpbox');
        try {
            const s = await api('/auth/twofa/setup', { method: 'POST' });
            box.innerHTML = `
                <p>${t('profile.totp_scan')}</p>
                <div class="mono" style="word-break:break-all;margin:8px 0">${esc(s.secret)}</div>
                <a class="mono" href="${esc(s.otpauth_uri)}">${t('profile.totp_open_app')}</a>
                <form id="totpconfirm" style="display:flex;margin-top:12px;gap:8px">
                    <input name="code" inputmode="numeric" pattern="\\d{6}" maxlength="6" required class="mono" placeholder="000000" style="width:120px">
                    <button class="btn primary">${t('profile.confirm')}</button>
                </form>`;
            document.getElementById('totpconfirm').addEventListener('submit', async (e) => {
                e.preventDefault();
                try {
                    await api('/auth/twofa/confirm', { method: 'POST', body: { code: new FormData(e.target).get('code') } });
                    toast(t('profile.totp_enabled'));
                    pageProfile();
                } catch (err) { toast(err.message, 'err'); }
            });
        } catch (err) { toast(err.message, 'err'); }
    });

    const renderKeys = async () => {
        const keys = await api('/auth/webauthn/keys');
        document.getElementById('keys').innerHTML = keys.length ? `
        <table class="data"><thead><tr><th>${t('profile.key_label')}</th><th class="hide-sm">${t('profile.created')}</th><th class="hide-sm">${t('profile.last_used')}</th><th></th></tr></thead>
        <tbody>${keys.map((k) => `<tr>
            <td>${icon('key')} ${esc(k.label)}</td>
            <td class="hide-sm">${fmtDate(k.created_at)}</td>
            <td class="hide-sm">${fmtDate(k.last_used_at)}</td>
            <td style="text-align:right"><button class="btn danger" data-del="${k.id}">${t('common.delete')}</button></td>
        </tr>`).join('')}</tbody></table>` : `<div class="empty">${t('profile.no_keys')}</div>`;

        document.getElementById('keys').querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', async () => {
            if (!confirm(t('profile.confirm_delete_key'))) return;
            try { await api(`/auth/webauthn/keys/${b.dataset.del}`, { method: 'DELETE' }); renderKeys(); }
            catch (err) { toast(err.message, 'err'); }
        }));
    };
    await renderKeys();

    document.getElementById('addkey').addEventListener('submit', async (e) => {
        e.preventDefault();
        const label = new FormData(e.target).get('label');
        try {
            if (!navigator.credentials) throw new Error(t('profile.webauthn_unsupported'));
            const o = await api('/auth/webauthn/register/options', { method: 'POST' });
            const cred = await navigator.credentials.create({ publicKey: {
                challenge: b64u.dec(o.challenge),
                rp: { id: o.rp_id, name: o.rp_name },
                user: { id: b64u.dec(o.user_id), name: o.user_name, displayName: o.user_name },
                pubKeyCredParams: [{ type: 'public-key', alg: -7 }, { type: 'public-key', alg: -257 }, { type: 'public-key', alg: -8 }],
                excludeCredentials: o.exclude.map((id) => ({ type: 'public-key', id: b64u.dec(id) })),
                authenticatorSelection: { userVerification: 'discouraged' },
                attestation: 'none',
                timeout: 60000,
            } });
            await api('/auth/webauthn/register', { method: 'POST', body: {
                label,
                credential_id: cred.id,
                attestation_object: b64u.enc(cred.response.attestationObject),
                client_data_json: b64u.enc(cred.response.clientDataJSON),
                transports: cred.response.getTransports?.() ?? [],
            } });
            toast(t('profile.key_added'));
            e.target.reset();
            renderKeys();
        } catch (err) { toast(err.message, 'err'); }
    });
}

// ---------------------------------------------------------------- router
const ROUTES = [
    [/^#\/profile$/, pageProfile],
    [/^#\/dashboard$/, pageDashboard],
    [/^#\/websites$/, pageWebsites],
    [/^#\/websites\/(\d+)$/, (m) => pageWebsiteDetail(Number(m[1]))],
    [/^#\/files$/, pageFiles],
    [/^#\/databases$/, pageDatabases],
    [/^#\/mail$/, pageMail],
    [/^#\/dns$/, pageDns],
    [/^#\/cloudflare$/, pageCloudflare],
    [/^#\/backups$/, pageBackups],
    [/^#\/ssl$/, pageSsl],
    [/^#\/tasks$/, pageTasks],
    [/^#\/monitoring$/, pageMonitoring],
    [/^#\/updates$/, pageUpdates],
    [/^#\/docker$/, pageDocker],
    [/^#\/security$/, pageSecurity],
    [/^#\/firewall$/, pageFirewall],
    [/^#\/config$/, pageConfig],
    [/^#\/assistant$/, pageAssistant],
    [/^#\/users$/, pageUsers],
];

async function route() {
    if (!state.me) return;
    // počisti monitoring auto-refresh pri svakoj navigaciji (izbjegni curenje)
    if (state.monTimer) { clearInterval(state.monTimer); state.monTimer = null; }
    const hash = location.hash || '#/dashboard';
    for (const [re, page] of ROUTES) {
        const m = hash.match(re);
        if (m) {
            try { await page(m); } catch (err) { main().innerHTML = `<div class="alert err">${esc(err.message)}</div>`; }
            return;
        }
    }
    location.hash = '#/dashboard';
}

window.addEventListener('hashchange', route);

// ---------------------------------------------------------------- start
async function enter() {
    state.me = await api('/auth/me');
    renderShell();
    if (!location.hash) location.hash = '#/dashboard';
    await route();
}

document.documentElement.dataset.theme = state.theme;
await loadLang();
await loadBranding();
if (state.token) {
    try { await enter(); } catch { logoutLocal(); }
} else {
    renderLogin();
}
