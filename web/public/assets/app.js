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
    railExpanded: localStorage.getItem('fp_rail') !== '0',
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
const PANEL_VERSION = '1.2.0';
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
    info: '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5"/><circle cx="12" cy="7.7" r="0.7" fill="currentColor" stroke="none"/>',
    chevR: '<path d="M9 5l7 7-7 7"/>',
    chevD: '<path d="M5 9l7 7 7-7"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    play: '<path d="M7 5l12 7-12 7z"/>',
    stop: '<rect x="6" y="6" width="12" height="12" rx="2"/>',
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
    if (res.status === 403 && json.error === 'license_required') { renderLicenseLock(); return Promise.reject(new Error('license_required')); }
    if (!json.ok) throw new Error(json.error ?? `http_${res.status}`);
    return json.data;
}

// Hard-lock zaslon: probni period istekao / nema licence → unos ključa (PUT /license je dopušten)
function renderLicenseLock() {
    if (document.getElementById('liclock')) return;
    if (state.monTimer) { clearInterval(state.monTimer); state.monTimer = null; }
    const b = brandName();
    $app.innerHTML = `<div id="liclock" class="login-wrap"><div class="login-card card">
        <div class="login-brand">
            <span class="mark">${state.branding?.logo_url ? `<img src="${esc(state.branding.logo_url)}" alt="">` : icon('lock', 20)}</span>
            <span class="name">${esc(b)}</span>
        </div>
        <h1 style="text-align:center;margin-bottom:6px">${t('lic.locked_title')}</h1>
        <p class="hint" style="text-align:center;margin-bottom:16px">${t('lic.locked_body')}</p>
        <form id="licform">
            <div class="field"><label>${t('lic.key')}</label><input name="key" class="mono" placeholder="FP-XXXX-XXXX-XXXX-XXXX-XXXX" required></div>
            <button class="btn primary" style="width:100%;justify-content:center">${icon('check')}${t('lic.activate')}</button>
        </form>
        <div id="licerr" class="alert err" style="margin-top:12px;display:none"></div>
        <div style="text-align:center;margin-top:14px"><button class="linklike" id="liclogout">${t('auth.logout')}</button></div>
    </div></div>`;
    document.getElementById('liclogout').addEventListener('click', doLogout);
    document.getElementById('licform').addEventListener('submit', async (e) => {
        e.preventDefault();
        const key = new FormData(e.target).get('key');
        const btn = e.target.querySelector('button');
        btn.disabled = true;
        const err = document.getElementById('licerr'); err.style.display = 'none';
        try {
            const r = await api('/license', { method: 'PUT', body: { license_key: key } });
            if (['active', 'trial'].includes(r.status)) { location.reload(); return; }
            throw new Error(r.status || 'invalid');
        } catch (ex) {
            err.textContent = `${t('lic.activate_failed')}: ${ex.message}`; err.style.display = '';
            btn.disabled = false;
        }
    });
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
        // Zatvori SAMO ako su i pritisak i otpuštanje na pozadini (ne kad se selekcija
        // teksta povuče iz editora pa otpusti izvan okvira).
        this.addEventListener('mousedown', (e) => { this._downOnBackdrop = e.target === this; });
        this.addEventListener('click', (e) => { if (e.target === this && this._downOnBackdrop) this.close(); });
        this._esc = (e) => { if (e.key === 'Escape') this.close(); };
        document.addEventListener('keydown', this._esc);
        this.querySelector('input, select, textarea, button')?.focus();
    }
    disconnectedCallback() { document.removeEventListener('keydown', this._esc); }
    close() { this.remove(); }
}
customElements.define('fp-modal', FpModal);

function openModal(html, { wide = false, editor = false } = {}) {
    const modal = document.createElement('fp-modal');
    modal.innerHTML = `<div class="dialog${wide ? ' wide' : ''}${editor ? ' editor' : ''}">${html}</div>`;
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
                // Obavezna 2FA, a korisnik je još nema → forsirani enrollment prije ulaska
                if (r.status === 'twofa_setup_required') return renderTwofaSetup(r.token);
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

// Forsirani 2FA enrollment na prijavi: obavezna 2FA, a korisnik je još nema.
// Ograničena setup-sesija (twofa_passed=0) smije SAMO postaviti TOTP; backend ju
// nakon potvrde podiže na punu. Onda prikažemo recovery kodove pa uđemo.
async function renderTwofaSetup(preToken) {
    state.token = preToken;
    const card = (inner) => {
        $app.innerHTML = `
        <div class="login-wrap"><div class="card login-card">
            <div class="login-brand">
                <div class="mark">${state.branding?.logo_url
                    ? `<img src="${esc(state.branding.logo_url)}" alt="">`
                    : icon('zap', 20)}</div>
                <div class="name">${esc(brandName())}</div>
            </div>
            <div class="alert err" hidden></div>
            ${inner}
        </div></div>`;
    };
    const showErr = (err) => {
        const a = $app.querySelector('.alert');
        if (!a) { toast(err.message, 'err'); return; }
        a.hidden = false;
        a.textContent = t('auth.' + err.message) !== 'auth.' + err.message ? t('auth.' + err.message) : err.message;
    };
    try {
        const s = await api('/auth/twofa/setup', { method: 'POST' });
        card(`
            <h2 style="margin:0 0 6px">${t('auth.twofa_setup_title')}</h2>
            <p>${t('auth.twofa_setup_required')}</p>
            <p>${t('profile.totp_scan')}</p>
            <div class="mono" style="word-break:break-all;margin:8px 0">${esc(s.secret)}</div>
            <a class="mono" href="${esc(s.otpauth_uri)}">${t('profile.totp_open_app')}</a>
            <form id="f" style="display:flex;gap:8px;margin-top:12px">
                <input name="code" inputmode="numeric" pattern="\\d{6}" maxlength="6" required autofocus class="mono" placeholder="000000" style="flex:1">
                <button class="btn primary">${t('profile.confirm')}</button>
            </form>`);
        document.getElementById('f').addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                const r = await api('/auth/twofa/confirm', { method: 'POST', body: { code: new FormData(e.target).get('code') } });
                card(`
                    <h2 style="margin:0 0 6px">${t('profile.recovery_title')}</h2>
                    <p>${t('profile.recovery_intro')}</p>
                    ${recoveryCodesHtml(r.recovery_codes || [])}
                    <button class="btn primary" id="cont" style="width:100%;margin-top:14px">${t('profile.recovery_saved')}</button>`);
                bindRecoveryCopy($app, r.recovery_codes || []);
                document.getElementById('cont').addEventListener('click', async () => {
                    localStorage.setItem('fp_token', preToken);
                    await enter();
                });
            } catch (err) { showErr(err); }
        });
    } catch (err) {
        renderLogin('login');
        showErr(err);
    }
}

// Recovery kodovi: grid + "kopiraj sve" (prikaz dijeljen login-setup i profil stranicom)
function recoveryCodesHtml(codes) {
    return `
    <div class="recovery-codes mono">${codes.map((c) => `<span>${esc(c)}</span>`).join('')}</div>
    <button class="btn sm" type="button" id="rcopy" style="margin-top:8px">${t('profile.recovery_copy')}</button>`;
}

function bindRecoveryCopy(root, codes) {
    root.querySelector('#rcopy')?.addEventListener('click', () => {
        navigator.clipboard?.writeText(codes.join('\n')).then(() => toast(t('profile.recovery_copied')));
    });
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
    { id: 'websites', icon: 'globe', label: 'nav.websites', key: 's', pages: ['websites'], section: 'nav.sec_web', count: 'vhosts' },
    { id: 'protect', icon: 'shield', label: 'nav.protect', key: 'p', pages: ['ssl', 'dns', 'cloudflare', 'security', 'firewall'] },
    { id: 'files', icon: 'folder', label: 'nav.files', key: 'f', pages: ['files'] },
    { id: 'databases', icon: 'db', label: 'nav.databases', key: 'b', pages: ['databases'], section: 'nav.sec_data', count: 'databases' },
    { id: 'mail', icon: 'mail', label: 'nav.mail', key: 'e', pages: ['mail', 'deliverability'], count: 'mail' },
    { id: 'backups', icon: 'download', label: 'nav.backups', key: 'a', pages: ['backups'] },
    { id: 'monitoring', icon: 'pulse', label: 'nav.monitoring', key: 'm', pages: ['monitoring', 'tasks'], section: 'nav.sec_system' },
    { id: 'docker', icon: 'box', label: 'nav.docker', key: 'k', pages: ['docker'] },
    { id: 'server', icon: 'server', label: 'nav.server', key: 'u', roles: ['admin'], pages: ['updates', 'config', 'system', 'migrator', 'distribution', 'licensing'] },
    { id: 'users', icon: 'users', label: 'nav.users', key: 'o', roles: ['admin', 'reseller'], pages: ['users'], count: 'users' },
];
const railVisible = (r) => !r.roles || r.roles.includes(state.me?.role);

const TAB_GROUPS = {
    mail: [['mail', 'nav.mail', 'mail'], ['deliverability', 'nav.deliverability', 'activity']],
    monitoring: [['monitoring', 'nav.monitoring', 'pulse'], ['tasks', 'nav.tasks', 'clock']],
    protect: [['ssl', 'nav.ssl', 'lock'], ['dns', 'nav.dns', 'globe'], ['cloudflare', 'nav.cloudflare', 'cloud', ['admin', 'reseller']], ['security', 'nav.security', 'shield'], ['firewall', 'nav.firewall', 'wall', 'admin']],
    server: [['updates', 'nav.updates', 'refresh'], ['config', 'nav.config', 'history'], ['system', 'nav.system', 'gear'], ['migrator', 'nav.migrator', 'upload'], ['distribution', 'nav.distribution', 'download'], ['licensing', 'nav.licensing', 'key']],
};

// tab strip za grupirane stranice (Zaštita: SSL · DNS · Sigurnost · Firewall, itd.)
function tabsHtml(groupId, activePage) {
    const tabs = (TAB_GROUPS[groupId] ?? []).filter(([, , , role]) =>
        !role || (Array.isArray(role) ? role.includes(state.me.role) : role === state.me.role));
    if (tabs.length < 2) return '';
    return `<nav class="tabs">${tabs.map(([page, key, ic]) =>
        `<a href="#/${page}" class="${page === activePage ? 'active' : ''}">${icon(ic)}${t(key)}</a>`).join('')}</nav>`;
}

// "+ Novo" / brze akcije — dijele ih topbar izbornik i kartica na ploči
const newActions = () => [
    { icon: 'globe', tile: 'tile-accent', label: 'qa.new_domain', go: '#/websites' },
    { icon: 'mail', tile: 'tile-accent', label: 'qa.new_mailbox', go: '#/mail' },
    { icon: 'db', tile: 'tile-ok', label: 'qa.new_database', go: '#/databases' },
    { icon: 'play', tile: 'tile-warn', label: 'qa.run_backup', go: '#/backups' },
    ...(state.me.role !== 'client' ? [{ icon: 'users', tile: 'tile-grape', label: 'qa.invite_user', go: '#/users' }] : []),
];

// generički popover izbornik usidren ispod gumba
function openMenu(anchor, html, align = 'left') {
    document.querySelectorAll('.menu-pop').forEach((p) => p.remove());
    const pop = document.createElement('div');
    pop.className = 'menu-pop';
    pop.innerHTML = html;
    document.body.append(pop);
    const r = anchor.getBoundingClientRect();
    const pw = pop.offsetWidth || 214;
    let left = align === 'right' ? r.right - pw : r.left;
    left = Math.max(8, Math.min(left, window.innerWidth - pw - 8));
    pop.style.top = `${Math.round(r.bottom + 6)}px`;
    pop.style.left = `${Math.round(left)}px`;
    setTimeout(() => document.addEventListener('click', function h(e) {
        if (!pop.contains(e.target) && e.target !== anchor) { pop.remove(); document.removeEventListener('click', h); }
    }), 0);
    return pop;
}

function renderShell() {
    const initials = state.me.email.slice(0, 2).toUpperCase();
    const isAdmin = state.me.role === 'admin';
    const hostShort = (location.hostname || brandName()).split('.')[0] || brandName();
    let curSection = '';
    const navHtml = RAIL.filter(railVisible).map((r) => {
        let sec = '';
        if (r.section && r.section !== curSection) { curSection = r.section; sec = `<div class="rail-section">${t(r.section)}</div>`; }
        const countSpan = r.count ? `<span class="rail-count" data-count="${r.count}"></span>` : '';
        return `${sec}
            <div class="rail-item">
                <button class="rail-btn" data-rail="${r.id}" data-go="#/${r.pages[0]}" aria-label="${t(r.label)}">${icon(r.icon)}<span class="rail-label">${t(r.label)}</span>${countSpan}</button>
                <span class="rail-tip">${t(r.label)} <span class="kbd-hint">G ${r.key.toUpperCase()}</span></span>
            </div>`;
    }).join('');

    $app.innerHTML = `
    <div class="shell">
        <nav class="rail${state.railExpanded ? ' expanded' : ''}" aria-label="Glavna navigacija">
            <button class="rail-collapse" id="railtoggle" aria-label="${t('nav.toggle')}" title="${t('nav.toggle')}">${icon('chevR')}</button>
            <div class="rail-logo" title="${esc(brandName())}">${state.branding?.logo_url
                ? `<img src="${esc(state.branding.logo_url)}" alt="${esc(brandName())}">`
                : icon('zap')}<span class="rail-label">${brandName() === 'ForgePanel' ? 'Forge<b>Panel</b>' : esc(brandName())}</span></div>
            <button class="rail-server" id="srvcard" title="${esc(location.hostname || brandName())}">
                <span class="rs-ic">${icon('server')}</span>
                <span class="rs-meta"><span class="rs-name">${esc(hostShort)}</span><span class="rs-sub" id="srvsub">Ubuntu 26.04 LTS</span></span>
                ${icon('chevD')}
            </button>
            ${navHtml}
            <div class="rail-spacer"></div>
            <div class="rail-item">
                <button class="rail-btn" data-go="#/about" data-rail="about" aria-label="${t('nav.about')}">${icon('info')}<span class="rail-label">${t('nav.about')}</span></button>
                <span class="rail-tip">${t('nav.about')}</span>
            </div>
            <div class="rail-storage" id="railstorage" hidden>
                <div class="rst-row"><span class="rst-k">${t('nav.storage')}</span><span class="rst-v" id="rst-v">—</span></div>
                <div class="bar"><i id="rst-bar" style="width:0%"></i></div>
                <button class="rst-up" data-go="${isAdmin ? '#/system' : '#/profile'}">${t('nav.upgrade_plan')} ${icon('arrowUR')}</button>
            </div>
        </nav>
        <div class="main">
            <header class="topbar">
                <button class="search-btn">${icon('search')}
                    <span class="search-label">${t('palette.placeholder')}</span>
                    <span class="keys"><span class="kbd">Ctrl</span><span class="kbd">K</span></span></button>
                ${isAdmin ? `<button class="ai-btn" id="aibtn" title="Forge AI (Ctrl+J)">${icon('sparkle')}<span class="ai-label">Forge AI</span></button>` : ''}
                <button class="icon-btn theme-btn" aria-label="Tema">${icon(state.theme === 'dark' ? 'sun' : 'moon')}</button>
                <button class="icon-btn tray-btn" aria-label="${t('nav.tasks')}">${icon('bell')}<span class="dot"></span></button>
                <button class="new-btn" id="newbtn">${icon('plus')}<span>${t('nav.new')}</span>${icon('chevD', 13)}</button>
                <button class="topbar-user" aria-label="${esc(state.me.email)}">
                    <span class="tu-meta"><span class="tu-name">${esc(state.me.email.split('@')[0])}</span><span class="tu-role">${esc(state.me.role)}</span></span>
                    <span class="av">${esc(initials)}</span>
                </button>
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
    $app.querySelector('#srvcard').addEventListener('click', () => { location.hash = isAdmin ? '#/monitoring' : '#/dashboard'; });
    $app.querySelector('.search-btn').addEventListener('click', () => palette.open());
    $app.querySelector('#aibtn')?.addEventListener('click', () => toggleAiDrawer());
    $app.querySelector('.theme-btn').addEventListener('click', (e) => {
        toggleTheme();
        e.currentTarget.innerHTML = icon(state.theme === 'dark' ? 'sun' : 'moon');
    });
    $app.querySelector('#newbtn').addEventListener('click', (e) => {
        e.stopPropagation();
        const pop = openMenu(e.currentTarget, `<div class="menu-head">${t('nav.new')}</div>` + newActions().map((a) =>
            `<button data-go="${a.go}"><span class="menu-tile ${a.tile}">${icon(a.icon)}</span>${t(a.label)}</button>`).join(''));
        pop.querySelectorAll('[data-go]').forEach((b) => b.addEventListener('click', () => { pop.remove(); location.hash = b.dataset.go; }));
    });
    $app.querySelector('.topbar-user').addEventListener('click', (e) => {
        e.stopPropagation();
        const pop = openMenu(e.currentTarget, `
            <div class="menu-who"><div class="em">${esc(state.me.email)}</div><div class="ro">${esc(state.me.role)}</div></div>
            <button data-act="profile">${icon('user')}${t('profile.title')}</button>
            <button data-act="logout">${icon('logout')}${t('auth.logout')}</button>`, 'right');
        pop.querySelector('[data-act="profile"]').addEventListener('click', () => { pop.remove(); location.hash = '#/profile'; });
        pop.querySelector('[data-act="logout"]').addEventListener('click', () => { pop.remove(); doLogout(); });
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
    populateShellMeta();
    if (state.aiOpen) renderAiDrawer();
}

// Brojači u sidebaru + pokazatelj pohrane — best-effort, ne blokiraju render
async function populateShellMeta() {
    const setCount = (key, n) => $app.querySelectorAll(`.rail-count[data-count="${key}"]`).forEach((el) => { el.textContent = n; });
    api('/vhosts').then((v) => { if (Array.isArray(v)) setCount('vhosts', v.length); }).catch(() => {});
    api('/databases').then((d) => { const a = Array.isArray(d) ? d : (d?.databases || []); setCount('databases', a.length); }).catch(() => {});
    if (state.me.role !== 'client') api('/mail/domains').then((m) => {
        const a = Array.isArray(m) ? m : (m?.domains || []);
        const n = a.reduce((s, x) => s + (Number(x.mailbox_count ?? x.mailboxes ?? 0) || 0), 0) || a.length;
        setCount('mail', n);
    }).catch(() => {});
    if (state.me.role !== 'client') api('/users').then((u) => { const a = Array.isArray(u) ? u : (u?.users || []); setCount('users', a.length); }).catch(() => {});

    // pohrana: admin → disk servera; ostali → kvota plana
    const box = $app.querySelector('#railstorage');
    const setStore = (used, total, sub) => {
        if (!box || !total) return;
        const pct = Math.min(100, Math.round(used / total * 100));
        box.hidden = false;
        const bar = box.querySelector('#rst-bar');
        bar.style.width = `${pct}%`;
        bar.className = pct >= 90 ? 'err' : pct >= 75 ? 'warn' : '';
        box.querySelector('#rst-v').textContent = `${fmtBytes(used)} / ${fmtBytes(total)}`;
        if (sub) { const sb = $app.querySelector('#srvsub'); if (sb) sb.textContent = sub; }
    };
    if (state.me.role === 'admin') {
        api('/monitoring/now').then((m) => {
            if (!m) return;
            setStore(m.disk_total_bytes - m.disk_free_bytes, m.disk_total_bytes, `${fmtBytes(m.mem_total_bytes)} RAM`);
        }).catch(() => {});
    } else {
        api('/dashboard/usage').then((u) => {
            if (u?.disk?.limit) setStore(u.disk.used, u.disk.limit);
        }).catch(() => {});
    }
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

// Generički task poller (bilo koji op) — čeka terminalni status iz tablice tasks
async function pollTask(id, { interval = 1500, maxMs = 180000 } = {}) {
    const start = Date.now();
    for (;;) {
        const r = await api(`/tasks/${id}`);
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

// Start/Stop/Restart gumbi za servis (samo admin). Klik se hvata delegirano (doc-level).
const svcActionsHtml = (name) => state.me?.role !== 'admin' ? '' : `<span class="svc-actions">
    <button class="svc-act start" data-svc-action="start" data-svc="${esc(name)}" title="${t('svc.start')}" aria-label="${t('svc.start')} ${esc(name)}">${icon('play', 13)}</button>
    <button class="svc-act restart" data-svc-action="restart" data-svc="${esc(name)}" title="${t('svc.restart')}" aria-label="${t('svc.restart')} ${esc(name)}">${icon('refresh', 13)}</button>
    <button class="svc-act stop" data-svc-action="stop" data-svc="${esc(name)}" title="${t('svc.stop')}" aria-label="${t('svc.stop')} ${esc(name)}">${icon('stop', 13)}</button></span>`;

async function doServiceAction(service, action, btn) {
    if (action === 'stop' && !confirm(`${t('svc.confirm_stop')} ${service}?`)) return;
    const grp = btn.closest('.svc-actions');
    grp?.querySelectorAll('button').forEach((b) => { b.disabled = true; });
    try {
        const r = await api('/monitoring/services/action', { method: 'POST', body: { service, action } });
        if (r.task_id) watchTask(r.task_id, `${service} · ${action}`);
        toast(`${service}: ${action}…`, 'ok');
        // partial osvježi (ne reload cijele stranice) nakon što servis sjedne;
        // refreshDashboard je otporan na prolazni pad pa neće bljesnuti "sve mrtvo"
        setTimeout(() => state.pageRefresh?.(), 4000);
    } catch (err) {
        toast(`${service}: ${err.message}`, 'err');
        grp?.querySelectorAll('button').forEach((b) => { b.disabled = false; });
    }
}
document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-svc-action]');
    if (b) doServiceAction(b.dataset.svc, b.dataset.svcAction, b);
});

// ── Cloud ploča: stat kartice, bar-chart, brze akcije, aktivnost, domene ──
const statCard = ({ ic, tile = 'tile-accent', label, value, unit = '', sub = '', pct = null, barTone = '', trend = null }) => `
    <div class="card stat">
        <div class="stat-top">
            <span class="stat-ic ${tile}">${icon(ic)}</span>
            <span class="stat-label">${label}</span>
            ${trend ? `<span class="stat-trend ${trend.tone || ''}">${trend.dir === 'down' ? '↓' : '↑'} ${trend.val}</span>` : ''}
        </div>
        <div class="stat-val tnum">${value}${unit ? `<span class="u">${unit}</span>` : ''}</div>
        ${pct != null ? `<div class="bar"><i class="${barTone}" style="width:${Math.min(100, Math.max(2, pct))}%"></i></div>` : '<div style="height:21px"></div>'}
        <div class="stat-sub">${sub}</div>
    </div>`;

// trend % iz povijesti (goodDown: pad je dobar → zeleno; inače akcent)
const dashTrend = (hist, goodDown = true) => {
    const v = (hist || []).map((p) => Number(p.value)).filter(Number.isFinite);
    if (v.length < 3) return null;
    const a = v[0], b = v[v.length - 1];
    if (!a) return null;
    const d = Math.round((b - a) / a * 100);
    if (!Number.isFinite(d) || Math.abs(d) < 1 || Math.abs(d) > 999) return null;
    return { dir: d < 0 ? 'down' : 'up', val: `${Math.abs(d)}%`, tone: goodDown ? (d <= 0 ? 'ok' : 'warn') : 'accent' };
};

// "6.2 GB" → "6.2<span class="u">GB…</span>"
const splitUnit = (bytes, suffix = '') => {
    const s = fmtBytes(bytes); const i = s.indexOf(' ');
    return i < 0 ? esc(s) : `${s.slice(0, i)}<span class="u">${s.slice(i + 1)}${suffix}</span>`;
};

const dashStats = (metrics, cpuHist, memHist, netRx) => {
    if (!metrics) return Array.from({ length: 4 }, () => statCard({ ic: 'pulse', label: '—', value: '—' })).join('');
    const cpuPct = Math.round(metrics.cpu_pct ?? Math.min(100, metrics.load[0] / metrics.cpu_count * 100));
    const ramUsed = metrics.mem_total_bytes - metrics.mem_available_bytes;
    const ramPct = Math.round((1 - metrics.mem_available_bytes / metrics.mem_total_bytes) * 100);
    const diskUsed = metrics.disk_total_bytes - metrics.disk_free_bytes;
    const diskPct = Math.round(diskUsed / metrics.disk_total_bytes * 100);
    const netNow = lastVal(netRx);
    const netMax = Math.max(1, ...(netRx || []).map((p) => Number(p.value)).filter(Number.isFinite));
    return [
        statCard({ ic: 'pulse', label: 'Procesor', value: cpuPct, unit: '%', pct: cpuPct,
            barTone: cpuPct >= 90 ? 'err' : cpuPct >= 70 ? 'warn' : '', trend: dashTrend(cpuHist, true),
            sub: `${metrics.cpu_count} ${t('dash.cores')} · load ${(metrics.load?.[0] ?? 0).toFixed(2)}` }),
        statCard({ ic: 'server', label: 'Memorija', value: `${splitUnit(ramUsed)}<span class="u">/ ${fmtBytes(metrics.mem_total_bytes)}</span>`,
            pct: ramPct, barTone: ramPct >= 90 ? 'err' : ramPct >= 75 ? 'warn' : '', trend: dashTrend(memHist, true),
            sub: `${ramPct}% ${t('dash.used')}` }),
        statCard({ ic: 'db', label: 'Disk', value: `${splitUnit(diskUsed)}<span class="u">/ ${fmtBytes(metrics.disk_total_bytes)}</span>`,
            pct: diskPct, barTone: diskPct >= 90 ? 'err' : diskPct >= 75 ? 'warn' : '',
            sub: `${fmtBytes(metrics.disk_free_bytes)} ${t('dash.free')}` }),
        statCard({ ic: 'arrowUR', label: 'Promet', value: netNow != null ? splitUnit(netNow, '/s') : '—',
            pct: netNow != null ? Math.round(netNow / netMax * 100) : null, trend: dashTrend(netRx, false),
            sub: t('dash.inbound') }),
    ].join('');
};

// server-load bar chart iz CPU povijesti
const dashChart = (hist) => {
    const pts = (hist || []).filter((p) => Number.isFinite(Number(p.value)));
    const v = pts.map((p) => Number(p.value));
    if (v.length < 2) return { bars: '<div class="empty" style="height:188px;display:grid;place-items:center">—</div>', peak: 0, avg: 0, peakLabel: '' };
    const N = 36, bucket = Math.ceil(v.length / N), buckets = [];
    for (let i = 0; i < v.length; i += bucket) { const seg = v.slice(i, i + bucket); buckets.push(seg.reduce((s, x) => s + x, 0) / seg.length); }
    const max = Math.max(...buckets, 1);
    const avg = Math.round(v.reduce((s, x) => s + x, 0) / v.length);
    let peakI = 0; v.forEach((x, i) => { if (x > v[peakI]) peakI = i; });
    return {
        bars: buckets.map((b) => `<div class="bc" style="height:${Math.max(3, Math.round(b / max * 100))}%"></div>`).join(''),
        peak: Math.round(v[peakI]), avg, peakLabel: pts[peakI]?.ts ? fmtTime(pts[peakI].ts) : '',
    };
};

const dashQuickActions = () => `<div class="qa-list">${newActions().map((a) =>
    `<button class="qa" data-go="${a.go}"><span class="qa-ic ${a.tile}">${icon(a.icon)}</span><span class="qa-label">${t(a.label)}</span>${icon('chevR', 17)}</button>`).join('')}</div>`;

const dashActivity = (feed) => {
    const items = [];
    (feed.events || []).forEach((e) => items.push({ ts: e.ts, sev: e.severity, ic: feedIcon(e.kind, e.severity), text: esc(e.text) }));
    (feed.deploys || []).forEach((d) => items.push({ ts: d.last_deploy_at, sev: 'ok', ic: 'branch', text: `${t('dash.deploy_published')} <b>${esc(d.domain)}</b>` }));
    if (!items.length) return `<div class="empty">${t('dash.no_activity')}</div>`;
    items.sort((a, b) => new Date(b.ts) - new Date(a.ts));
    const tile = { ok: 'tile-ok', err: 'tile-danger', warn: 'tile-warn', info: 'tile-info' };
    return items.slice(0, 7).map((it) => `
        <div class="act">
            <span class="act-ic ${tile[it.sev] || 'tile-muted'}">${icon(it.ic)}</span>
            <div class="act-body"><div class="act-text">${it.text}</div><div class="act-time">${timeAgo(it.ts)}</div></div>
        </div>`).join('');
};

const domSsl = (days) => days == null ? '<span class="mono" style="color:var(--ink-3)">—</span>'
    : days < 21 ? `<span class="badge warn">${days} ${t('dash.days')}</span>`
    : `<span class="badge ok"><span class="dot ok"></span>${t('dash.ssl_valid')}</span>`;

const dashDomains = (vhosts) => vhosts.length ? `
    <table class="data"><thead><tr>
        <th>${t('vhost.domain')}</th><th>SSL</th><th>PHP</th><th class="num">Disk</th>
    </tr></thead><tbody>
    ${vhosts.slice(0, 6).map((v) => `
        <tr class="row-link" data-vhost="${v.id}">
            <td><div class="dom-cell"><span class="dom-ic">${icon('globe', 15)}</span>
                <div><div class="dom-name">${esc(v.domain)}</div>${appLabel(v.app_type) ? `<div class="dom-sub">${appLabel(v.app_type)}</div>` : ''}</div></div></td>
            <td>${domSsl(v.ssl_days)}</td>
            <td class="mono">${esc(v.php_version || '—')}</td>
            <td class="num mono">${v.disk_bytes != null ? fmtBytes(Number(v.disk_bytes)) : '—'}</td>
        </tr>`).join('')}
    </tbody></table>` : `<div class="empty">${t('nav.websites')}: 0</div>`;

const dashNotice = (vhosts) => {
    const exp = vhosts.filter((v) => v.ssl_days != null && v.ssl_days <= 14).sort((a, b) => a.ssl_days - b.ssl_days)[0];
    if (!exp) return '';
    return `<div class="notice">
        <span class="notice-ic">${icon('shield')}</span>
        <div class="notice-body"><div class="notice-title">${t('dash.ssl_expiring_title')}</div>
            <div class="notice-sub"><span class="mono">${esc(exp.domain)}</span> · ${t('dash.expires_in')} ${exp.ssl_days} ${t('dash.days')}</div></div>
        <a class="btn primary" href="#/websites/${exp.id}">${t('dash.renew_now')}</a>
        <button class="notice-x" data-dismiss>${icon('x')}</button>
    </div>`;
};

async function refreshDashboard(cfConnected) {
    const body = document.getElementById('dashbody');
    if (!body) return;
    const range = state.dashRange || '24h';
    const [vhosts, metrics, services, feed, insights] = await Promise.all([
        api('/vhosts').catch(() => []),
        api('/monitoring/now').catch(() => null),
        api('/monitoring/services').catch(() => ({})),
        api('/dashboard/feed').catch(() => ({ events: [], deploys: [] })),
        api('/dashboard/insights').catch(() => ({ items: [] })),
    ]);
    const [cpuHist, memHist, netRx, cpuChartHist] = await Promise.all([
        api('/monitoring/history?metric=cpu_pct&range=1h').catch(() => []),
        api('/monitoring/history?metric=mem_used_bytes&range=1h').catch(() => []),
        api('/monitoring/history?metric=net_rx_bps&range=1h').catch(() => []),
        api(`/monitoring/history?metric=cpu_pct&range=${range}`).catch(() => []),
    ]);

    const upCount = vhosts.filter((v) => v.status === 'active').length;
    const problems = vhosts.filter((v) => v.status === 'error' || v.status === 'suspended').length;
    const sv = Object.entries(services);
    const healthy = sv.filter(([, p]) => p.ActiveState === 'active').length;
    const uptimeTxt = metrics ? `${Math.floor(metrics.uptime_s / 86400)}d ${Math.floor((metrics.uptime_s % 86400) / 3600)}h` : '—';

    const statsHtml = dashStats(metrics, cpuHist, memHist, netRx);
    const chart = dashChart(cpuChartHist);
    const noticeHtml = dashNotice(vhosts);
    const activityHtml = dashActivity(feed);
    const domainsHtml = dashDomains(vhosts);
    const insightsBlock = insights.items.length ? `
        <div class="ai-box dash-ai">
            <span class="mark">${icon('sparkle')}</span>
            <div style="min-width:0;flex:1">
                <div style="font-weight:650;margin-bottom:5px">Forge AI · ${insights.items.length} ${insights.items.length === 1 ? 'preporuka' : 'preporuke'}</div>
                ${insights.items.map((it) => `<div class="dash-ai-row">${dot(it.severity)}<span>${esc(it.text)}</span></div>`).join('')}
                ${insights.items.find((i) => i.ai_prompt) ? `<div style="margin-top:10px"><button class="btn small primary" id="dashai">${icon('sparkle')}${t('dash.open_analysis')}</button></div>` : ''}
            </div>
        </div>` : '';
    const svcRows = sv.map(([name, p]) => `<tr>
        <td><span style="display:flex;align-items:center;gap:8px;font-weight:550">${dot(p.ActiveState === 'active' ? 'ok' : p.ActiveState === 'failed' ? 'err' : 'warn')}<span class="mono">${esc(name)}</span></span></td>
        <td><span class="badge ${p.ActiveState === 'active' ? 'ok' : p.ActiveState === 'failed' ? 'err' : ''}">${esc(p.SubState || p.ActiveState || '?')}</span></td>
        <td class="mono num">${p.cpu_pct != null ? p.cpu_pct.toFixed(1) + '%' : '—'}</td>
        <td class="mono num">${p.mem_bytes != null ? fmtBytes(p.mem_bytes) : '—'}</td>
        <td class="mono num hide-sm">${svcUptime(p)}</td>
        <td class="svc-actions-cell">${svcActionsHtml(name)}</td></tr>`).join('');
    const healthCls = healthy === sv.length ? 'ok' : 'warn';
    const healthTxt = `${healthy}/${sv.length} ${t('dash.healthy')}`;
    body._aiPrompt = insights.items.find((i) => i.ai_prompt)?.ai_prompt || null;

    const setHtml = (id, html) => { const el = document.getElementById(id); if (el && el.innerHTML !== html) el.innerHTML = html; };
    const hostShort = (location.hostname || brandName()).split('.')[0] || brandName();
    const pills = ['24h', '7d', '30d'].map((r) => `<button class="pill${r === range ? ' active' : ''}" data-range="${r}">${r}</button>`).join('');

    if (body.dataset.built !== '1') {
        body.innerHTML = `
        <div class="page-head">
            <div class="page-titles"><h1>${t('nav.dashboard')}</h1>
                <div class="page-sub">${dot('ok', true)}<span class="mono">${esc(hostShort)}</span> · uptime <b id="dash-uptime">${uptimeTxt}</b> · Ubuntu 26.04 LTS</div></div>
            <span class="spacer"></span>
            <div class="pillbar" id="dash-range">${pills}</div>
            <a class="btn" href="#/monitoring">${icon('download')}${t('dash.report')}</a>
        </div>
        <div id="dash-notice">${noticeHtml}</div>
        <div id="dash-insights"${insightsBlock ? ' style="margin-bottom:var(--gap)"' : ''}>${insightsBlock}</div>
        <div class="grid cols-4" id="dash-stats">${statsHtml}</div>
        <div class="dash-grid" style="margin-top:var(--gap)">
            <div class="dash-left">
                <section class="card flush">
                    <div class="card-head">
                        <div style="min-width:0"><h2 style="margin:0">${t('dash.server_load')}</h2>
                            <div class="hint" style="margin-top:2px">${t('dash.server_load_sub')}</div></div>
                        <span class="spacer"></span>
                        <div><div class="chart-card-val" id="dash-peak">${chart.peak}%</div>
                            <div class="chart-card-cap">${t('dash.peak_at')} <span id="dash-peaklbl">${chart.peakLabel || '—'}</span></div></div>
                    </div>
                    <div class="pad">
                        <div class="loadchart" id="dash-chart">${chart.bars}</div>
                        <div class="loadchart-x"><span>00:00</span><span>06:00</span><span>12:00</span><span>18:00</span><span>sad</span></div>
                    </div>
                </section>
            </div>
            <div class="dash-right">
                <section class="card flush">
                    <div class="card-head"><h2>${t('dash.quick_actions')}</h2></div>
                    <div id="dash-qa" style="padding:4px 10px 8px">${dashQuickActions()}</div>
                </section>
            </div>
        </div>
        <div class="dash-grid" style="margin-top:var(--gap)">
            <div class="dash-left">
                <section class="card flush">
                    <div class="card-head"><h2>${t('nav.websites')}</h2><span class="count">${vhosts.length}</span><span class="spacer"></span>
                        <a class="btn small ghost" href="#/websites">${t('dash.all_domains')} ${icon('arrowUR')}</a></div>
                    <div id="dash-domains">${domainsHtml}</div>
                </section>
            </div>
            <div class="dash-right">
                <section class="card flush">
                    <div class="card-head"><h2>${t('dash.recent_activity')}</h2><span class="spacer"></span>
                        <span class="live-dot">${dot('ok', true)} live</span></div>
                    <div class="pad" id="dash-activity" style="padding-top:6px;padding-bottom:10px">${activityHtml}</div>
                </section>
            </div>
        </div>
        <section class="card flush" style="margin-top:var(--gap)">
            <div class="card-head"><h2>${t('dash.services')}</h2><span class="count" id="dash-svccount">${sv.length} procesa</span>
                <span class="badge ${healthCls}" id="dash-health">${healthTxt}</span><span class="spacer"></span>
                <button class="btn small ghost" id="chkupd">${icon('refresh')}${t('dash.check_updates')}</button>
                <a class="btn small" href="#/monitoring">${t('dash.all')} ${icon('chevR')}</a></div>
            <table class="data svc-table"><thead><tr><th>${t('mon.service')}</th><th>${t('mon.state')}</th><th class="num">CPU</th><th class="num">RAM</th><th class="num hide-sm">Uptime</th><th></th></tr></thead>
            <tbody id="dash-svc">${svcRows}</tbody></table>
        </section>`;
        body.dataset.built = '1';

        // delegirani klikovi (preživljavaju osvježavanja)
        body.addEventListener('click', (e) => {
            if (e.target.closest('#dashai')) { state.aiPending = body._aiPrompt; toggleAiDrawer(true); return; }
            if (e.target.closest('[data-dismiss]')) { document.getElementById('dash-notice').innerHTML = ''; return; }
            const pill = e.target.closest('[data-range]');
            if (pill) {
                state.dashRange = pill.dataset.range;
                document.querySelectorAll('#dash-range .pill').forEach((p) => p.classList.toggle('active', p === pill));
                refreshDashboard(cfConnected); return;
            }
            const go = e.target.closest('.qa[data-go]'); if (go) { location.hash = go.dataset.go; return; }
            const vt = e.target.closest('[data-vhost]'); if (vt) { location.hash = `#/websites/${vt.dataset.vhost}`; }
        });
        const chk = document.getElementById('chkupd');
        if (chk) chk.addEventListener('click', async () => {
            chk.disabled = true;
            const orig = chk.innerHTML;
            chk.innerHTML = `${icon('refresh')}${t('dash.checking')}`;
            try {
                const r = await api('/updates/scan', { method: 'POST' });
                watchTask(r.task_id, t('dash.check_updates'));
                const res = await pollTask(r.task_id);
                if (res.status !== 'done') throw new Error(res.error || 'scan_failed');
                const comps = await api('/updates').catch(() => []);
                const n = comps.filter((c) => c.available_version && c.available_version !== c.current_version).length;
                toast(n ? `${t('dash.updates_available')}: ${n}` : t('dash.updates_none'), n ? 'warn' : 'ok');
                if (n) location.hash = '#/updates';
            } catch (err) { toast(err.message, 'err'); }
            finally { chk.disabled = false; chk.innerHTML = orig; }
        });
    } else {
        // ── osvježavanje: samo dinamični dijelovi ──
        const apiDown = !metrics && sv.length === 0;
        if (apiDown) return;

        if (metrics) {
            setHtml('dash-stats', statsHtml);
            const up = document.getElementById('dash-uptime'); if (up) up.textContent = uptimeTxt;
            setHtml('dash-chart', chart.bars);
            const pk = document.getElementById('dash-peak'); if (pk) pk.textContent = `${chart.peak}%`;
            const pl = document.getElementById('dash-peaklbl'); if (pl) pl.textContent = chart.peakLabel || '—';
        }
        setHtml('dash-notice', noticeHtml);
        setHtml('dash-insights', insightsBlock);
        document.getElementById('dash-insights')?.style.setProperty('margin-bottom', insightsBlock ? 'var(--gap)' : '0');
        setHtml('dash-activity', activityHtml);
        setHtml('dash-domains', domainsHtml);

        if (sv.length > 0) {
            setHtml('dash-svc', svcRows);
            setHtml('dash-svccount', `${sv.length} procesa`);
            const hb = document.getElementById('dash-health'); if (hb) { hb.className = `badge ${healthCls}`; hb.textContent = healthTxt; }
        }
    }
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
    // partial refresh fn za vanjske okidače (npr. nakon akcije nad servisom)
    state.pageRefresh = () => refreshDashboard(cf.connected);
    // live auto-refresh feeda + KPI svakih 10 s (čisti se u route() pri navigaciji)
    state.monTimer = setInterval(() => refreshDashboard(cf.connected), 10000);
    // klik na red domene ide kroz delegirani handler u refreshDashboard
}

// klijentski dashboard — potrošnja plana, SSL istek, statusi, status stranica
async function pageDashboardClient() {
    main().innerHTML = `<div class="empty">${t('common.loading')}</div>`;
    const [vhosts, certs, usage, status] = await Promise.all([
        api('/vhosts').catch(() => []),
        api('/ssl').catch(() => []),
        api('/dashboard/usage').catch(() => ({})),
        api('/status').catch(() => ({ enabled: false })),
    ]);
    const upCount = vhosts.filter((v) => v.status === 'active').length;
    // SSL pri isteku (≤ 14 dana) iz vhost liste (ssl_days) ili ssl_certs
    const expiring = vhosts.filter((v) => v.ssl_days != null && v.ssl_days <= 14);

    const usageBar = (u, label, fmt = (x) => x) => {
        if (!u || !u.limit) return '';
        const pct = Math.min(100, Math.round(u.used / u.limit * 100));
        const tone = pct >= 90 ? 'meter-bad' : (pct >= 75 ? 'meter-warn' : 'meter-ok');
        return `<div class="usage-item">
            <div class="row" style="justify-content:space-between"><span>${label}</span>
                <span class="mono small">${fmt(u.used)} / ${fmt(u.limit)}</span></div>
            <div class="meter"><span class="${tone}" style="width:${pct}%"></span></div></div>`;
    };

    const pctOf = (u) => u?.limit ? Math.min(100, Math.round(u.used / u.limit * 100)) : null;
    main().innerHTML = `
    <div class="page-head">
        <div class="page-titles"><h1>${t('nav.dashboard')}</h1>
            <div class="page-sub">${dot('ok', true)}${upCount} ${t('dash.active')} · ${vhosts.length} ${t('nav.websites').toLowerCase()}</div></div>
    </div>
    <div class="grid cols-4" style="margin-bottom:var(--gap)">
        ${statCard({ ic: 'globe', label: t('nav.websites'), value: vhosts.length, sub: `${upCount} ${t('dash.active')}`, pct: pctOf(usage.domains) })}
        ${statCard({ ic: 'lock', label: t('nav.ssl'), value: certs.length, sub: expiring.length ? `${expiring.length} ${t('dash.expiring')}` : t('dash.ssl_valid') })}
        ${statCard({ ic: 'mail', label: t('dash.mailboxes'), value: usage.mailboxes?.used ?? 0, sub: usage.mailboxes?.limit ? `/ ${usage.mailboxes.limit}` : '', pct: pctOf(usage.mailboxes) })}
        ${statCard({ ic: 'db', label: t('nav.databases'), value: usage.databases?.used ?? 0, sub: usage.databases?.limit ? `/ ${usage.databases.limit}` : '', pct: pctOf(usage.databases) })}
    </div>
    <div class="grid cols-2">
        <div class="card">
            <div class="card-head"><h2>${t('dash.plan_usage')}</h2></div>
            ${usageBar(usage.disk, t('dash.disk'), fmtBytes)}
            ${usageBar(usage.domains, t('nav.websites'))}
            ${usageBar(usage.mailboxes, t('dash.mailboxes'))}
            ${usageBar(usage.databases, t('nav.databases'))}
            ${!usage.disk?.limit ? `<div class="empty">${t('dash.no_plan')}</div>` : ''}
        </div>
        <div class="card" id="statuscard">
            <div class="card-head"><h2>${t('dash.status_page')}</h2></div>
            <p class="hint">${t('dash.status_hint')}</p>
            <div id="statusbody"></div>
        </div>
    </div>
    ${expiring.length ? `<div class="card flush mt">
        <div class="card-head"><h2>${t('dash.ssl_expiring')}</h2></div>
        <table class="data"><tbody>${expiring.map((v) => `<tr>
            <td class="mono">${esc(v.domain)}</td>
            <td class="num"><span class="badge ${v.ssl_days < 5 ? 'err' : 'warn'}">${v.ssl_days} ${t('dash.days')}</span></td>
        </tr>`).join('')}</tbody></table></div>` : ''}
    <div class="card flush mt">
        <div class="card-head"><h2>${t('nav.websites')}</h2></div>
        ${vhostTable(vhosts.slice(0, 12))}
    </div>`;
    bindVhostRows();
    renderStatusCard(status);
}

function renderStatusCard(status) {
    const box = document.getElementById('statusbody');
    if (!box) return;
    if (status.enabled && status.token) {
        const url = status.url || `${location.origin}/status/${status.token}`;
        box.innerHTML = `
            <div class="field"><label>${t('dash.status_url')}</label>
                <input class="mono" readonly value="${esc(url)}" id="statusurl"></div>
            <div class="row" style="gap:8px">
                <a class="btn sm" href="${esc(url)}" target="_blank" rel="noopener">${icon('arrowUR')}${t('dash.open')}</a>
                <button class="btn sm" id="statuscopy">${t('dash.copy')}</button>
                <button class="btn sm danger" id="statusoff">${t('dash.disable')}</button>
            </div>`;
        box.querySelector('#statuscopy').addEventListener('click', () => {
            navigator.clipboard?.writeText(url); toast(t('dash.copied'));
        });
        box.querySelector('#statusoff').addEventListener('click', async () => {
            try { await api('/status', { method: 'DELETE' }); renderStatusCard({ enabled: false }); }
            catch (err) { toast(err.message, 'err'); }
        });
    } else {
        box.innerHTML = `<button class="btn primary" id="statuson">${icon('plus')}${t('dash.enable_status')}</button>`;
        box.querySelector('#statuson').addEventListener('click', async () => {
            try { renderStatusCard(await api('/status/enable', { method: 'POST' })); toast(t('dash.status_enabled')); }
            catch (err) { toast(err.message, 'err'); }
        });
    }
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
const stackText = (v) => v.web_backend === 'redirect'
    ? `redirect · ${v.redirect_code || 301}`
    : `PHP ${esc(v.php_version)} · ${v.web_backend === 'nginx_apache' ? 'apache' : 'nginx'}`;
const fmtNum = (n) => { n = Number(n) || 0; return n >= 1000 ? (n / 1000).toFixed(n >= 10000 ? 0 : 1).replace('.0', '') + 'k' : String(n); };
const parseSpark = (s) => { try { const a = JSON.parse(s || '[]'); return Array.isArray(a) ? a : []; } catch { return []; } };
const sparkBars = (arr) => {
    if (!arr.length || arr.every((x) => !x)) return '<div class="spark-flat"></div>';
    const max = Math.max(1, ...arr);
    return `<div class="spark">${arr.map((v) => `<i style="height:${Math.max(6, Math.round((v / max) * 100))}%"></i>`).join('')}</div>`;
};

const sitesList = (vhosts, selectedId) => {
    if (!vhosts.length) return `<div class="empty">${t('nav.websites')}: 0</div>`;
    const domains = new Set(vhosts.map((v) => v.domain));
    const byId = new Map(vhosts.map((v) => [String(v.id), v.domain]));
    // matični vhost: eksplicitni parent_vhost_id (Dodaj poddomenu) ili heuristika po sufiksu
    // domene (staging i poddomene zavedene prije parent_vhost_id polja)
    const parentOf = (v) => {
        // redirect domena se gnijezdi ispod domene na koju preusmjerava
        if (v.web_backend === 'redirect' && v.redirect_target && domains.has(v.redirect_target)) return v.redirect_target;
        if (v.parent_vhost_id != null && byId.has(String(v.parent_vhost_id))) return byId.get(String(v.parent_vhost_id));
        const parts = v.domain.split('.');
        for (let i = 1; i < parts.length - 1; i++) {
            const cand = parts.slice(i).join('.');
            if (domains.has(cand)) return cand;
        }
        return null;
    };
    // grupiraj djecu odmah ispod matičnog
    const sorted = [...vhosts].sort((a, b) => {
        const ra = parentOf(a) || a.domain, rb = parentOf(b) || b.domain;
        if (ra !== rb) return ra < rb ? -1 : 1;
        const ca = parentOf(a) ? 1 : 0, cb = parentOf(b) ? 1 : 0;
        if (ca !== cb) return ca - cb;
        return a.domain < b.domain ? -1 : 1;
    });
    return `
    <table class="data sites-table"><thead><tr>
        <th>${t('sites.col_site')}</th>
        <th class="hide-sm">${t('sites.col_stack')}</th>
        <th class="hide-md num">${t('sites.col_traffic')}</th>
        <th>SSL</th>
        <th class="hide-md">${t('sites.col_deploy')}</th>
        <th class="num">${t('sites.col_disk')}</th>
    </tr></thead><tbody>
    ${sorted.map((v) => {
        const child = parentOf(v) !== null;
        return `
        <tr class="row-link ${child ? 'child' : ''} ${v.id === selectedId ? 'selected' : ''}" data-vhost="${v.id}">
            <td><div class="site-cell${child ? ' nested' : ''}"><span class="status-dot ${dotKind(v)}"></span>
                <div><a href="#/websites/${v.id}" class="mono site-name" data-open>${esc(v.domain)}</a>
                ${v.web_backend === 'redirect'
                    ? `<div class="sub redir">↪ ${esc(v.redirect_target || '')}</div>`
                    : (appLabel(v.app_type) ? `<div class="sub">${appLabel(v.app_type)}</div>` : '')}</div></div></td>
            <td class="mono hide-sm sub2">${stackText(v)}</td>
            <td class="mono hide-md num sub2">${v.traffic_7d != null ? fmtNum(v.traffic_7d) : '—'}</td>
            <td>${sslBadge(v.ssl_days)}</td>
            <td class="mono hide-md sub2">${v.git_branch ? esc(v.git_branch) + ' · ' + timeAgo(v.git_last_deploy) : '—'}</td>
            <td class="num mono sub2">${v.disk_bytes != null ? fmtBytes(Number(v.disk_bytes)) : '—'}</td>
        </tr>`;
    }).join('')}
    </tbody></table>`;
};

const sitePanel = (v) => !v ? `<div class="empty">${t('sites.select')}</div>` : `
    <div class="sp-head">
        <div class="sp-titles"><div class="sp-title mono">${esc(v.domain)}</div>
            <div class="sp-sub">${stackText(v)}${appLabel(v.app_type) ? ' · ' + appLabel(v.app_type) : ''}</div></div>
        <a class="btn ghost icon" href="#/websites/${v.id}" title="${t('sites.open')}">${icon('arrowUR')}</a>
    </div>
    <div class="sp-spark">${sparkBars(parseSpark(v.traffic_spark))}<div class="sp-spark-cap">${t('sites.visits_24h')} · ${fmtNum(parseSpark(v.traffic_spark).reduce((a, b) => a + (Number(b) || 0), 0))}</div></div>
    <div class="sp-stats">
        <div class="sp-stat"><span class="k">${t('sites.col_traffic')}</span><span class="vv mono">${v.traffic_7d != null ? fmtNum(v.traffic_7d) : '—'}</span></div>
        <div class="sp-stat"><span class="k">${t('sites.visits_24h_short')}</span><span class="vv mono">${fmtNum(parseSpark(v.traffic_spark).reduce((a, b) => a + (Number(b) || 0), 0))}</span></div>
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
        <button class="btn" id="newsub">${icon('plus')}${t('subdomain.create')}</button>
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
    document.getElementById('newsub').addEventListener('click', () => createSubdomainModal(vhosts, selectedId));

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
        // klik na ime stranice otvara detalje (href navigira), ne samo select panela
        list.querySelectorAll('[data-open]').forEach((a) => a.addEventListener('click', (e) => e.stopPropagation()));
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
                            ${state.me.role === 'admin' ? `<option value="reapply_isolation">${t('bulk.reapply_isolation')}</option>` : ''}
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
    // Cloudflare: izbor računa s kojim se domena povezuje (DNS zapisi idu na taj CF račun)
    let cfAccounts = [];
    try { cfAccounts = (await api('/cloudflare/account')).accounts || []; } catch { /* CF nije povezan */ }
    const cfField = cfAccounts.length ? `
            <div class="field"><label>${t('vhost.cf_account')}</label>
                <select name="cf_account_id" id="vcfacct" class="mono">
                    <option value="">${t('vhost.cf_local')}</option>
                    ${cfAccounts.map((a) => `<option value="${a.id}">${esc(a.name)}</option>`).join('')}
                </select>
                <span class="hint">${t('vhost.cf_hint')}</span></div>
            <label class="inline" id="vcfproxy" style="display:none;margin-bottom:var(--gap)">
                <input type="checkbox" name="cf_proxy" checked> ${t('cf.proxy')}</label>` : '';
    // Redirect: stranica može biti samo 301/302 na neku postojeću (ne zaseban paket)
    let sites = [];
    try { sites = await api('/vhosts'); } catch { /* nema postojećih */ }
    const typeField = sites.length ? `
            <div class="field"><label>${t('vhost.type')}</label>
                <select name="web_backend" id="vtype" class="mono">
                    <option value="nginx">${t('vhost.type_full')}</option>
                    <option value="redirect">${t('vhost.type_redirect')}</option>
                </select></div>` : '';
    const redirectField = sites.length ? `
            <div id="vredirect" style="display:none">
                <div class="field"><label>${t('vhost.redirect_target')}</label>
                    <select name="redirect_target" class="mono">
                        ${sites.map((s) => `<option value="${esc(s.domain)}">${esc(s.domain)}</option>`).join('')}
                    </select></div>
                <div class="field"><label>${t('vhost.redirect_code')}</label>
                    <select name="redirect_code" class="mono">
                        <option value="301">${t('vhost.redirect_301')}</option>
                        <option value="302">${t('vhost.redirect_302')}</option>
                    </select></div></div>` : '';
    const modal = openModal(`
        <div class="dialog-head"><h1>${t('vhost.create')}</h1><button class="btn ghost icon" data-close>${icon('x')}</button></div>
        <form id="vf">
            <div class="field"><label>${t('vhost.domain')}</label>
                <input name="domain" required placeholder="example.com" class="mono" autocomplete="off">
                <span class="hint">Bez www — alias se dodaje automatski (AutoSSL pokriva oba).</span></div>
            ${typeField}
            ${subField}
            <div class="field" id="vphp"><label>${t('vhost.php_version')}</label>
                <select name="php_version">${['8.5', '8.4', '8.3', '8.2', '8.1'].map((v) => `<option>${v}</option>`).join('')}</select></div>
            ${redirectField}
            ${cfField}
            <div class="dialog-foot">
                <button type="button" class="btn" data-close>${t('common.cancel')}</button>
                <button class="btn primary">${t('common.create')}</button>
            </div>
        </form>`);
    // Proxy (narančasti oblak) checkbox vidljiv samo kad je odabran CF račun
    const acctSel = modal.querySelector('#vcfacct');
    acctSel?.addEventListener('change', () => {
        modal.querySelector('#vcfproxy').style.display = acctSel.value ? '' : 'none';
    });
    // Tip: redirect skriva PHP verziju i prikazuje izbor ciljne stranice
    const typeSel = modal.querySelector('#vtype');
    typeSel?.addEventListener('change', () => {
        const red = typeSel.value === 'redirect';
        modal.querySelector('#vredirect').style.display = red ? '' : 'none';
        const php = modal.querySelector('#vphp');
        if (php) php.style.display = red ? 'none' : '';
    });
    modal.querySelector('#vf').addEventListener('submit', async (e) => {
        e.preventDefault();
        const body = Object.fromEntries(new FormData(e.target));
        if (body.web_backend !== 'redirect') {
            delete body.redirect_target;
            delete body.redirect_code;
        }
        if (!body.cf_account_id) {
            delete body.cf_account_id; // "lokalni DNS" — bez CF-a
        } else {
            body.cf_proxy = modal.querySelector('[name=cf_proxy]')?.checked ?? true;
        }
        try {
            const r = await api('/vhosts', { method: 'POST', body });
            modal.close();
            watchTask(r.task_id, `vhost.create ${body.domain}`);
            watchTask(r.ssl_task_id, `ssl.issue ${body.domain}`);
            if (r.dns) {
                if (r.dns.ok === false) {
                    toast(r.dns.error === 'cf_zone_not_found' ? t('vhost.cf_zone_missing') : t('vhost.dns_failed'), 'warn');
                } else if (r.dns.mode === 'cloudflare') {
                    toast(t('vhost.cf_done'), 'ok');
                } else if (r.dns.mode === 'local') {
                    toast(t('vhost.dns_done'), 'ok');
                }
            }
            location.hash = '#/websites';
            route();
        } catch (err) { toast(err.message, 'err'); }
    });
}

// Dodaj poddomenu (Plesk-style) — bira matičnu domenu + labelu; backend automatski
// kreira vlastiti docroot/FPM/SSL i DNS u infrastrukturi roditelja.
async function createSubdomainModal(vhostsHint = null, preselectId = null) {
    let sites = Array.isArray(vhostsHint) ? vhostsHint : [];
    if (!sites.length) { try { sites = await api('/vhosts'); } catch { /* */ } }
    // Moguća matična domena = prava (hostana) stranica, ne redirect i ne sama poddomena
    const parents = sites.filter((s) => s.web_backend !== 'redirect' && !s.parent_vhost_id);
    if (!parents.length) { toast(t('subdomain.no_parent'), 'err'); return; }
    // Ako je odabran red bio poddomena → preselektiraj njenog roditelja
    const pre = sites.find((s) => String(s.id) === String(preselectId));
    const preParentId = pre ? (pre.parent_vhost_id || pre.id) : null;
    const curId = parents.some((s) => String(s.id) === String(preParentId)) ? String(preParentId) : String(parents[0].id);
    const domainOf = (id) => parents.find((s) => String(s.id) === String(id))?.domain || '';

    const modal = openModal(`
        <div class="dialog-head"><h1>${t('subdomain.create')}</h1><button class="btn ghost icon" data-close>${icon('x')}</button></div>
        <form id="sf">
            <div class="field"><label>${t('subdomain.parent')}</label>
                <select name="parent_id" id="sparent" class="mono">
                    ${parents.map((s) => `<option value="${s.id}" ${String(s.id) === curId ? 'selected' : ''}>${esc(s.domain)}</option>`).join('')}
                </select></div>
            <div class="field"><label>${t('subdomain.label')}</label>
                <div class="sub-input">
                    <input name="label" required placeholder="blog" class="mono" autocomplete="off" id="slabel">
                    <span class="sub-suffix" id="ssuffix">.${esc(domainOf(curId))}</span>
                </div>
                <span class="hint">${t('subdomain.hint')}</span></div>
            <div class="field"><label>${t('vhost.php_version')}</label>
                <select name="php_version">${['8.5', '8.4', '8.3', '8.2', '8.1'].map((v) => `<option>${v}</option>`).join('')}</select></div>
            <div class="dialog-foot">
                <button type="button" class="btn" data-close>${t('common.cancel')}</button>
                <button class="btn primary">${t('common.create')}</button>
            </div>
        </form>`);
    const parentSel = modal.querySelector('#sparent');
    const suffix = modal.querySelector('#ssuffix');
    parentSel.addEventListener('change', () => { suffix.textContent = '.' + domainOf(parentSel.value); });
    modal.querySelector('#sf').addEventListener('submit', async (e) => {
        e.preventDefault();
        const f = Object.fromEntries(new FormData(e.target));
        const fqdn = `${(f.label || '').toLowerCase()}.${domainOf(f.parent_id)}`;
        try {
            const r = await api(`/vhosts/${f.parent_id}/subdomains`, { method: 'POST', body: { label: f.label, php_version: f.php_version } });
            modal.close();
            watchTask(r.task_id, `vhost.create ${fqdn}`);
            if (r.ssl_task_id) watchTask(r.ssl_task_id, `ssl.issue ${fqdn}`);
            if (r.dns && (r.dns.local || r.dns.cloudflare)) toast(t('subdomain.dns_done'), 'ok');
            location.hash = '#/websites';
            route();
        } catch (err) {
            const m = {
                plan_subdomain_limit_reached: t('subdomain.err_limit'),
                domain_exists: t('subdomain.err_exists'),
                parent_not_hostable: t('subdomain.err_parent'),
                invalid_subdomain: t('subdomain.err_label'),
            }[err.message];
            toast(m || err.message, 'err');
        }
    });
}

// Popravak DNS-a za postojeću domenu (kreira lokalnu zonu ako fali + opcionalno CF)
async function repairDnsModal(vhost) {
    let cfAccounts = [];
    try { cfAccounts = (await api('/cloudflare/account')).accounts || []; } catch { /* CF nije povezan */ }
    const cfField = cfAccounts.length ? `
            <div class="field"><label>${t('vhost.cf_account')}</label>
                <select name="cf_account_id" id="rcfacct" class="mono">
                    <option value="">${t('vhost.cf_local')}</option>
                    ${cfAccounts.map((a) => `<option value="${a.id}">${esc(a.name)}</option>`).join('')}
                </select>
                <span class="hint">${t('vhost.cf_hint')}</span></div>
            <label class="inline" id="rcfproxy" style="display:none;margin-bottom:var(--gap)">
                <input type="checkbox" name="cf_proxy" checked> ${t('cf.proxy')}</label>` : '';
    const modal = openModal(`
        <div class="dialog-head"><h1>${t('vhost.dns_repair')}</h1><button class="btn ghost icon" data-close>${icon('x')}</button></div>
        <form id="rdf">
            <p class="hint">${t('vhost.dns_repair_hint')} <span class="mono">${esc(vhost.domain)}</span></p>
            ${cfField}
            <div class="dialog-foot">
                <button type="button" class="btn" data-close>${t('common.cancel')}</button>
                <button class="btn primary">${t('vhost.dns_repair_go')}</button>
            </div>
        </form>`);
    const acctSel = modal.querySelector('#rcfacct');
    acctSel?.addEventListener('change', () => {
        modal.querySelector('#rcfproxy').style.display = acctSel.value ? '' : 'none';
    });
    modal.querySelector('#rdf').addEventListener('submit', async (e) => {
        e.preventDefault();
        const body = Object.fromEntries(new FormData(e.target));
        if (!body.cf_account_id) {
            delete body.cf_account_id;
        } else {
            body.cf_proxy = modal.querySelector('[name=cf_proxy]')?.checked ?? true;
        }
        try {
            const r = await api(`/vhosts/${vhost.id}/dns/repair`, { method: 'POST', body });
            modal.close();
            if (r.dns && r.dns.ok === false) {
                toast(r.dns.error === 'cf_zone_not_found' ? t('vhost.cf_zone_missing') : t('vhost.dns_failed'), 'warn');
            } else {
                toast(t('vhost.dns_repair_done'), 'ok');
            }
        } catch (err) { toast(err.message, 'err'); }
    });
}

// PHP postavke po domeni (Plesk-style) — override u FPM pool; prazno = PHP default
function phpSettingsCard(vhost) {
    const ps = (() => { try { return JSON.parse(vhost.php_settings || '{}') || {}; } catch { return {}; } })();
    const v = (k) => esc(ps[k] ?? '');
    const sel = (k, opts) => `<select name="${k}" class="mono"><option value="">${t('php.default')}</option>${opts.map(([val, lbl]) =>
        `<option value="${val}" ${String(ps[k] ?? '') === val ? 'selected' : ''}>${lbl}</option>`).join('')}</select>`;
    const num = [
        ['memory_limit', '256M'], ['max_execution_time', '120'], ['max_input_time', '120'],
        ['post_max_size', '128M'], ['upload_max_filesize', '128M'], ['max_input_vars', '5000'],
    ];
    return `
    <details class="card php-card mt">
        <summary class="card-collapse">${icon('chevR')}<h2>${t('php.title')}</h2><span class="spacer"></span>
            <span class="hint mono">PHP ${esc(vhost.php_version)}</span></summary>
        <form id="phpform" class="mt">
            <div class="grid cols-3">
                ${num.map(([k, ph]) => `<div class="field"><label>${k}</label><input name="${k}" class="mono" placeholder="${ph}" value="${v(k)}"></div>`).join('')}
                <div class="field"><label>opcache.enable</label>${sel('opcache.enable', [['1', 'on'], ['0', 'off']])}</div>
                <div class="field"><label>display_errors</label>${sel('display_errors', [['On', 'On'], ['Off', 'Off']])}</div>
                <div class="field span-all"><label>disable_functions</label>
                    <input name="disable_functions" class="mono" placeholder="exec,system,shell_exec,passthru" value="${v('disable_functions')}"></div>
            </div>
            <div class="php-foot"><span class="hint">${t('php.hint')}</span>
                <button class="btn primary" type="submit">${t('common.save')}</button></div>
        </form>
    </details>`;
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
        <button class="btn" id="dnsrepair">${icon('globe')}${t('vhost.dns_repair')}</button>
        <button class="btn" id="renew">${icon('refresh')}SSL renew</button>
        <button class="btn danger" id="del">${t('common.delete')}</button>
    </div>
    <div id="diagbox"></div>
    <div class="grid cols-2">
        <div class="card">
            <h2>Postavke</h2>
            <table class="data"><tbody>
                ${vhost.web_backend === 'redirect' ? `
                <tr><td>${t('vhost.type')}</td><td><span class="badge info">${t('vhost.type_redirect')}</span></td></tr>
                <tr><td>${t('vhost.redirect_target')}</td><td class="mono">${vhost.redirect_code || 301} → ${esc(vhost.redirect_target || '')}</td></tr>
                ` : `
                <tr><td>${t('vhost.php_version')}</td><td>
                    ${vhost.web_backend === 'php_legacy'
                        ? `<span class="badge warn">PHP 7.2.34 · Docker (legacy)</span>`
                        : `<select id="php" class="mono">${['8.1', '8.2', '8.3', '8.4', '8.5'].map((v) =>
                            `<option ${v === vhost.php_version ? 'selected' : ''}>${v}</option>`).join('')}</select>`}</td></tr>
                <tr><td>Backend</td><td>
                    <select id="backend" class="mono">
                        <option value="nginx" ${vhost.web_backend === 'nginx' ? 'selected' : ''}>nginx + FPM (brže)</option>
                        <option value="nginx_apache" ${vhost.web_backend === 'nginx_apache' ? 'selected' : ''}>nginx → Apache (.htaccess)</option>
                        <option value="php_legacy" ${vhost.web_backend === 'php_legacy' ? 'selected' : ''}>Legacy PHP 7.2 (Docker)</option>
                    </select></td></tr>`}
                <tr><td>Sistemski user</td><td class="mono">${esc(vhost.sys_user)}</td></tr>
                <tr><td>Docroot</td><td class="mono">${esc(vhost.docroot)}</td></tr>
                <tr><td>Kreirano</td><td>${fmtDate(vhost.created_at)}</td></tr>
            </tbody></table>
        </div>
        <div class="card">
            <div class="page-head"><h2>${t('nav.files')}</h2></div>
            <div class="fm-launch">
                <span class="fm-launch-ic">${icon('folder', 22)}</span>
                <div class="fm-launch-info">
                    <div class="mono fm-launch-path">${esc(vhost.docroot)}</div>
                    <div class="hint">${t('files.open_hint')}</div>
                </div>
                <button class="btn primary" id="openfm">${icon('folder')}${t('files.open')}</button>
            </div>
        </div>
    </div>
    ${phpSettingsCard(vhost)}
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

    main().querySelector('#php')?.addEventListener('change', async (e) => {
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
    main().querySelector('#dnsrepair').addEventListener('click', () => repairDnsModal(vhost));
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

    main().querySelector('#backend')?.addEventListener('change', async (e) => {
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

    main().querySelector('#phpform')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const settings = {};
        for (const [k, v] of fd.entries()) { if (String(v).trim() !== '') settings[k] = String(v).trim(); }
        const btn = e.target.querySelector('button[type=submit],button.primary');
        if (btn) btn.disabled = true;
        try {
            const r = await api(`/vhosts/${id}/php-settings`, { method: 'PUT', body: { settings } });
            if (r.task_id) watchTask(r.task_id, `PHP postavke ${vhost.domain}`);
            toast(t('php.saved'), 'ok');
        } catch (err) { toast(err.message, 'err'); }
        finally { if (btn) btn.disabled = false; }
    });

    main().querySelector('#openfm').addEventListener('click', () => {
        sessionStorage.setItem('fp_files_vhost', String(vhost.id));
        location.hash = '#/files';
    });
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

const APP_ICONS = { wordpress: 'box', nextcloud: 'cloud', ghost: 'sparkle', node: 'zap', python: 'terminal' };

async function appsSection(vhost, container) {
    container.innerHTML = `<div class="empty">${t('common.loading')}</div>`;
    const catalog = await api('/apps/catalog').catch(() => []);
    container.innerHTML = `
        <div id="installedapps"></div>
        <div class="addform-h" style="margin-bottom:10px">${t('apps.available')}</div>
        <div class="app-grid">
            ${catalog.map((a) => `<div class="app-card">
                <div class="app-h">${icon(APP_ICONS[a.id] || 'box')}<b>${esc(a.name)}</b></div>
                <p class="hint">${esc(a.desc)}</p>
                <button class="btn primary sm" data-app="${esc(a.id)}">${icon('plus')}${t('apps.install')}</button>
            </div>`).join('')}
        </div>
        <div class="row mt" style="gap:8px"><button class="btn sm" id="wpcheck">${icon('shield')}${t('apps.wp_integrity')}</button></div>
        <div id="appsresult" class="mt"></div>`;

    loadInstalledApps(vhost, container.querySelector('#installedapps'));
    const result = container.querySelector('#appsresult');
    container.querySelectorAll('[data-app]').forEach((b) => b.addEventListener('click', () => installApp(vhost, b.dataset.app, result)));
    container.querySelector('#wpcheck').addEventListener('click', async () => {
        try {
            const r = await api(`/vhosts/${vhost.id}/apps/wordpress/checksums`, { method: 'POST' });
            watchTask(r.task_id, `WP integritet ${vhost.domain}`);
        } catch (err) { toast(err.message, 'err'); }
    });
}

// Popis instaliranih aplikacija (WordPress/Nextcloud/Ghost) — vlastiti sub-container da
// se može osvježiti nakon instalacije bez gubitka prikaza kredencijala.
async function loadInstalledApps(vhost, box) {
    if (!box) return;
    const installed = await api(`/vhosts/${vhost.id}/apps`).catch(() => []);
    if (!installed.length) { box.innerHTML = ''; return; }
    box.innerHTML = `
        <div class="addform-h" style="margin-bottom:10px">${t('apps.installed')}</div>
        <div class="installed-apps">
            ${installed.map((a) => `<div class="installed-app">
                <span class="ia-ic">${icon(APP_ICONS[a.type] || 'box')}</span>
                <div class="ia-meta"><div class="ia-name">${esc(a.name)}</div>
                    <div class="ia-sub mono">${esc(a.domain)}${a.installed_at ? ' · ' + fmtDate(a.installed_at) : ''}</div></div>
                <a class="btn sm" href="${esc(a.admin_url)}" target="_blank" rel="noopener">${icon('arrowUR')}${a.type === 'wordpress' ? 'wp-admin' : t('apps.open')}</a>
                <button class="btn ghost sm danger" data-uninstall="${esc(a.type)}" title="${t('apps.uninstall')}">${icon('x', 13)}${t('apps.uninstall')}</button>
            </div>`).join('')}
        </div>
        <div class="hr" style="margin:16px 0 14px"></div>`;

    box.querySelectorAll('[data-uninstall]').forEach((b) => b.addEventListener('click', async () => {
        if (!confirm(t('apps.uninstall_confirm'))) return;
        try {
            await api(`/vhosts/${vhost.id}/apps/${b.dataset.uninstall}`, { method: 'DELETE' });
            toast(t('apps.uninstalled'));
            loadInstalledApps(vhost, box);
        } catch (err) { toast(err.message, 'err'); }
    }));
}

async function installApp(vhost, appId, result) {
    const refresh = (taskId) => {
        if (!taskId) return;
        pollTask(taskId).then((res) => {
            if (res.status === 'done') loadInstalledApps(vhost, main().querySelector('#installedapps'));
        }).catch(() => {});
    };
    try {
        if (appId === 'node' || appId === 'python') {
            const entry = prompt(t(appId === 'node' ? 'apps.node_entry' : 'apps.python_entry'), appId === 'node' ? 'index.js' : 'app:app');
            if (!entry) return;
            const r = await api(`/vhosts/${vhost.id}/${appId}`, { method: 'POST', body: { entry } });
            watchTask(r.task_id, `${appId} ${vhost.domain}`);
            return;
        }
        // PHP/Node CMS — kreira bazu + DB usera automatski
        const r = await api(`/vhosts/${vhost.id}/apps/${appId}`, { method: 'POST', body: {} });
        watchTask(r.task_id, `${appId} ${vhost.domain}`);
        if (appId === 'wordpress') {
            result.innerHTML = `<div class="alert ok">${t('apps.wp_db_created')}: <span class="mono">${esc(r.db_name)}</span> / <span class="mono">${esc(r.db_password)}</span></div>`;
        } else if (appId === 'nextcloud') {
            result.innerHTML = `<div class="alert ok">${t('apps.nc_admin')}: <span class="mono">${esc(r.admin_user)}</span> / <span class="mono">${esc(r.admin_password)}</span></div>`;
        } else if (appId === 'ghost') {
            result.innerHTML = `<div class="alert ok">${t('apps.ghost_started')}</div>`;
        }
        refresh(r.task_id);
    } catch (err) { toast(err.message, 'err'); }
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
            <button class="btn danger sm" data-delsel hidden>${icon('archive', 14)}<span data-delcount></span></button>
            <button class="icon-btn" data-upload title="${t('files.upload')}">${icon('upload')}</button>
            <button class="icon-btn" data-mkdir title="${t('files.mkdir')}">${icon('folder')}</button>
            <input type="file" hidden>
        </div>
        <table>
            <thead><tr>
                <th class="fm-check"><input type="checkbox" data-selall aria-label="${t('files.select_all')}"></th>
                <th class="fm-ic"></th><th>${t('files.name')}</th>
                <th class="meta num hide-sm">${t('files.size')}</th>
                <th class="meta hide-sm">${t('files.mode')}</th>
                <th class="meta hide-sm">${t('files.modified')}</th>
            </tr></thead>
            <tbody>
            ${entries.map((en, i) => `
            <tr data-i="${i}">
                <td class="fm-check"><input type="checkbox" class="fsel" value="${esc(en.name)}"></td>
                <td class="cell-icon">${icon(en.type === 'dir' ? 'folder' : 'file', 15)}</td>
                <td class="mono">${esc(en.name)}</td>
                <td class="meta num hide-sm">${en.type === 'file' ? fmtBytes(en.size_bytes) : ''}</td>
                <td class="meta hide-sm">${esc(en.mode)}</td>
                <td class="meta hide-sm">${fmtDate(en.mtime)}</td>
            </tr>`).join('') || `<tr><td colspan="6"><div class="empty">${t('files.empty')}</div></td></tr>`}
            </tbody>
        </table>
    </div>`;

    const delBtn = container.querySelector('[data-delsel]');
    const updateSel = () => {
        const n = container.querySelectorAll('.fsel:checked').length;
        delBtn.hidden = n === 0;
        container.querySelector('[data-delcount]').textContent = ` ${t('common.delete')} (${n})`;
    };
    container.querySelector('[data-selall]').addEventListener('change', (e) => {
        container.querySelectorAll('.fsel').forEach((c) => { c.checked = e.target.checked; });
        updateSel();
    });
    container.querySelectorAll('.fsel').forEach((c) => {
        c.addEventListener('click', (e) => e.stopPropagation());
        c.addEventListener('change', updateSel);
    });
    delBtn.addEventListener('click', async () => {
        const names = [...container.querySelectorAll('.fsel:checked')].map((c) => c.value);
        if (names.length === 0 || !confirm(`${t('files.confirm_delete_n')} (${names.length})`)) return;
        try {
            for (const name of names) {
                await api(`/vhosts/${vhost.id}/files/delete`, { method: 'POST', body: { path: `${relPath}/${name}` } });
            }
            toast(`${t('common.delete')}: ${names.length}`);
            fileManager(vhost, container, relPath);
        } catch (err) { toast(err.message, 'err'); }
    });

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
    container.querySelectorAll('[data-i]').forEach((tr) => tr.addEventListener('click', (e) => {
        if (e.target.closest('.fm-check')) return; // klik na checkbox ne otvara file
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
        <div class="code-editor">
            <div class="code-gutter" aria-hidden="true"></div>
            <textarea class="code-edit" spellcheck="false" wrap="off">${esc(content)}</textarea>
        </div>
        <div class="dialog-foot">
            <button class="btn danger" id="fdel">${t('common.delete')}</button>
            <button class="btn" id="fdl">${icon('download')}${t('files.download')}</button>
            <span class="spacer" style="flex:1"></span>
            <button class="btn" data-close>${t('common.cancel')}</button>
            <button class="btn primary" id="fsave">${t('common.save')}</button>
        </div>`, { editor: true });

    // Brojevi linija — sinkronizirani sa sadržajem i skrolom
    const ta = modal.querySelector('.code-edit');
    const gutter = modal.querySelector('.code-gutter');
    const renderGutter = () => {
        const n = ta.value.split('\n').length;
        if (gutter._n === n) return;
        gutter._n = n;
        let s = '';
        for (let i = 1; i <= n; i++) s += i + '\n';
        gutter.textContent = s;
    };
    ta.addEventListener('input', renderGutter);
    ta.addEventListener('scroll', () => { gutter.scrollTop = ta.scrollTop; });
    renderGutter();

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
    let current = vhosts.find((v) => v.id === saved) ?? vhosts[0];
    // Redirect domena nema vlastiti docroot — file manager otvara matičnu (ciljnu) domenu
    if (current.web_backend === 'redirect' && current.redirect_target) {
        const target = vhosts.find((v) => v.domain === current.redirect_target);
        if (target) {
            toast(t('files.redirect_to').replace('%s', current.domain).replace('%t', target.domain), 'info');
            current = target;
            sessionStorage.setItem('fp_files_vhost', String(current.id));
        }
    }
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
    <div id="pmabar"></div>
    <div class="card" id="dblist">${t('common.loading')}</div>`;
    document.getElementById('new').addEventListener('click', createDbModal);

    const [dbs, pma] = await Promise.all([
        api('/databases'),
        api('/databases/pma/status').catch(() => ({ installed: true })),
    ]);

    if (state.me.role === 'admin' && !pma.installed) {
        document.getElementById('pmabar').innerHTML = `
            <div class="alert warn" style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
                <span style="flex:1">${t('db.pma_install_hint')}</span>
                <button class="btn sm" id="pmainstall">${icon('download')}${t('db.pma_install')}</button></div>`;
        document.getElementById('pmainstall').addEventListener('click', async (e) => {
            e.target.disabled = true;
            try {
                const r = await api('/databases/pma/install', { method: 'POST' });
                watchTask(r.task_id, 'phpMyAdmin install');
                toast(t('db.pma_installing'));
            } catch (err) { toast(err.message, 'err'); e.target.disabled = false; }
        });
    }

    const list = document.getElementById('dblist');
    list.innerHTML = dbs.length ? dbs.map((d) => `
        <div class="db-item">
            <div class="db-head">
                <div class="db-meta"><span class="mono db-title">${esc(d.name)}</span>
                    <span class="db-sub mono">${fmtBytes(d.size_bytes)} · ${fmtDate(d.created_at)}</span></div>
                <div class="db-acts">
                    <button class="btn ghost" data-pma="${d.id}">${t('db.pma')}</button>
                    <button class="btn ghost" data-user="${d.id}">${icon('plus')}${t('db.user')}</button>
                    <button class="btn danger" data-del="${d.id}" data-name="${esc(d.name)}">${t('common.delete')}</button>
                </div>
            </div>
            <div class="db-users">
                ${(d.users && d.users.length) ? d.users.map((u) => `
                    <div class="db-user">
                        <span class="mono">${esc(u.username)}</span>
                        <span class="badge ${Number(u.remote_access) ? 'warn' : ''}">${Number(u.remote_access) ? t('db.remote') : t('db.local')}</span>
                        <div class="spacer"></div>
                        <button class="btn ghost sm" data-uedit="${u.id}" data-db="${d.id}">${t('common.edit')}</button>
                        <button class="btn danger sm" data-udel="${u.id}" data-db="${d.id}" data-uname="${esc(u.username)}">${t('common.delete')}</button>
                    </div>`).join('') : `<div class="db-nousers">${t('db.no_users')}</div>`}
            </div>
        </div>`).join('') : `<div class="empty">${t('nav.databases')}: 0</div>`;

    list.querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', async () => {
        if (!confirm(`${t('common.confirm_delete')} (${b.dataset.name})`)) return;
        try { await api(`/databases/${b.dataset.del}`, { method: 'DELETE' }); pageDatabases(); }
        catch (err) { toast(err.message, 'err'); }
    }));
    list.querySelectorAll('[data-user]').forEach((b) => b.addEventListener('click', () => createDbUserModal(b.dataset.user)));
    list.querySelectorAll('[data-uedit]').forEach((b) => b.addEventListener('click', () => {
        const d = dbs.find((x) => String(x.id) === b.dataset.db);
        editDbUserModal(b.dataset.db, d.users.find((x) => String(x.id) === b.dataset.uedit));
    }));
    list.querySelectorAll('[data-udel]').forEach((b) => b.addEventListener('click', async () => {
        if (!confirm(`${t('common.confirm_delete')} (${b.dataset.uname})`)) return;
        try { await api(`/databases/${b.dataset.db}/users/${b.dataset.udel}`, { method: 'DELETE' }); pageDatabases(); }
        catch (err) { toast(err.message, 'err'); }
    }));
    // phpMyAdmin auto-login: jednokratan signed token → nova kartica
    list.querySelectorAll('[data-pma]').forEach((b) => b.addEventListener('click', async () => {
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
            <div class="field"><label>${t('db.username')}</label><input name="username" required pattern="[a-z][a-z0-9_]{2,31}" class="mono"></div>
            <div class="field"><label>${t('auth.password')}</label><input name="password" type="password" required minlength="12">
                <span class="hint">min. 12 znakova</span></div>
            <label class="chk"><input type="checkbox" name="remote_access" value="1"> ${t('db.remote_access')}</label>
            <div class="dialog-foot"><button type="button" class="btn" data-close>${t('common.cancel')}</button>
                <button class="btn primary">${t('common.create')}</button></div>
        </form>`);
    modal.querySelector('#uf').addEventListener('submit', async (e) => {
        e.preventDefault();
        const f = new FormData(e.target);
        try {
            await api(`/databases/${dbId}/users`, { method: 'POST', body: {
                username: f.get('username'), password: f.get('password'), remote_access: f.get('remote_access') === '1',
            } });
            modal.close(); pageDatabases();
        } catch (err) { toast(err.message, 'err'); }
    });
}

function editDbUserModal(dbId, user) {
    const modal = openModal(`
        <div class="dialog-head"><h1>${t('db.user_edit')}: <span class="mono">${esc(user.username)}</span></h1>
            <button class="btn ghost icon" data-close>${icon('x')}</button></div>
        <form id="euf">
            <div class="field"><label>${t('auth.password')}</label>
                <input name="password" type="password" minlength="12" placeholder="${t('users.password_keep')}">
                <span class="hint">${t('db.pw_keep_hint')}</span></div>
            <label class="chk"><input type="checkbox" name="remote_access" value="1" ${Number(user.remote_access) ? 'checked' : ''}> ${t('db.remote_access')}</label>
            <div class="dialog-foot"><button type="button" class="btn" data-close>${t('common.cancel')}</button>
                <button class="btn primary">${t('common.save')}</button></div>
        </form>`);
    modal.querySelector('#euf').addEventListener('submit', async (e) => {
        e.preventDefault();
        const f = new FormData(e.target);
        const body = { remote_access: f.get('remote_access') === '1' };
        if (f.get('password')) body.password = f.get('password');
        try { await api(`/databases/${dbId}/users/${user.id}`, { method: 'PUT', body }); modal.close(); pageDatabases(); }
        catch (err) { toast(err.message, 'err'); }
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
                <td class="mono">${esc(c.hostname)}${c.status === 'error' && c.last_error
                    ? `<div class="mono" style="color:#e5484d;font-size:11px;margin-top:3px;white-space:normal;max-width:560px">${esc(c.last_error)}</div>` : ''}</td>
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

// Zaokruži gore na "lijep" broj (1/2/5 × 10ⁿ) za y-os
function niceCeil(n) {
    if (!(n > 0)) return 1;
    const exp = Math.floor(Math.log10(n));
    const base = Math.pow(10, exp);
    const f = n / base;
    const nice = f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10;
    return nice * base;
}
// "YYYY-MM-DD HH:MM:SS" → HH:MM ili DD.MM. (dugi raspon)
function fmtTs(ts, longSpan) {
    const d = new Date(String(ts).replace(' ', 'T'));
    if (isNaN(d)) return '';
    const p = (x) => String(x).padStart(2, '0');
    return longSpan ? `${p(d.getDate())}.${p(d.getMonth() + 1)}.` : `${p(d.getHours())}:${p(d.getMinutes())}`;
}

// Grafana-style area panel: y-os oznake + gridlines + vremenska x-os
function sparkline(points, { height = 150, formatY = (v) => String(v), color = 'var(--accent)' } = {}) {
    if (!points || points.length < 2) return `<div class="empty" style="padding:40px 0">${t('common.loading')}</div>`;
    const values = points.map((p) => Number(p.value));
    const niceMax = niceCeil(Math.max(...values, 0)) || 1;
    const width = 600;
    const x = (i) => (i / (values.length - 1)) * width;
    const y = (v) => height - (v / niceMax) * height;
    const coords = values.map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(' ');
    const grid = [0, 0.25, 0.5, 0.75, 1].map((tk) =>
        `<line x1="0" x2="${width}" y1="${(height * tk).toFixed(1)}" y2="${(height * tk).toFixed(1)}"/>`).join('');
    const yLabels = [1, 0.75, 0.5, 0.25, 0].map((tk) => `<span>${formatY(niceMax * tk)}</span>`).join('');
    const first = new Date(String(points[0].ts).replace(' ', 'T'));
    const last = new Date(String(points.at(-1).ts).replace(' ', 'T'));
    const longSpan = (last - first) > 2 * 86400 * 1000;
    const mid = Math.floor((points.length - 1) / 2);
    const xLabels = [0, mid, points.length - 1].map((i) => `<span>${fmtTs(points[i].ts, longSpan)}</span>`).join('');
    return `
    <div class="g-chart" style="--gh:${height}px">
        <div class="g-yaxis">${yLabels}</div>
        <div class="g-plot">
            <svg viewBox="0 0 ${width} ${height}" preserveAspectRatio="none" class="g-svg" role="img">
                <g class="g-grid">${grid}</g>
                <polygon points="0,${height} ${coords} ${width},${height}" fill="${color}" opacity="0.13"/>
                <polyline points="${coords}" fill="none" stroke="${color}" stroke-width="2"
                    vector-effect="non-scaling-stroke" stroke-linejoin="round"/>
            </svg>
        </div>
    </div>
    <div class="g-xaxis">${xLabels}</div>`;
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
                <td class="mono num hide-sm">${svcUptime(s)}</td>
                <td class="svc-actions-cell">${svcActionsHtml(name)}</td></tr>`;
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
                <table class="data svc-table"><thead><tr><th>${t('mon.service')}</th><th>${t('mon.state')}</th><th class="num">CPU</th><th class="num">RAM</th><th class="num hide-sm">Uptime</th><th></th></tr></thead>
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
            ${state.me.role === 'admin' ? `<button class="btn sm" id="alarmcfg">${icon('bell')}${t('alarm.config')}</button>` : ''}
            <span class="live-dot">${Number(state.monRefresh) > 0 ? dot('ok', true) + ' streaming · ' + state.monRefresh + 's' : 'pauzirano'}</span>
            <label class="inline mono" style="gap:6px">${t('mon.refresh')}
                <select id="monref" class="mono">${MON_REFRESH.map(refOpt).join('')}</select></label>
        </div>
        <div id="mondata"><div class="empty">${t('common.loading')}</div></div>`;

    state.pageRefresh = refreshMonitoring;
    document.getElementById('alarmcfg')?.addEventListener('click', openAlarmConfig);

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

// Alarm konfiguracija: kanali (e-mail/Telegram/webhook) + pragovi CPU/RAM/disk
async function openAlarmConfig() {
    const cfg = await api('/monitoring/alarms').catch(() => ({}));
    const ch = cfg.channels || {};
    const tg = ch.telegram || {};
    const thr = cfg.thresholds || {};
    const modal = openModal(`
        <div class="dialog-head"><h1>${icon('bell')}${t('alarm.title')}</h1><button class="btn ghost icon" data-close>${icon('x')}</button></div>
        <form id="alf">
            <label class="inline" style="gap:8px;margin-bottom:12px"><input type="checkbox" name="enabled" ${cfg.enabled ? 'checked' : ''}> ${t('alarm.enabled')}</label>
            <h2>${t('alarm.channels')}</h2>
            <div class="field"><label>${t('alarm.email')}</label><input name="email" type="email" class="mono" value="${esc(ch.email || '')}" placeholder="ops@example.com"></div>
            <div class="grid cols-2">
                <div class="field"><label>${t('alarm.tg_token')}</label><input name="tg_token" class="mono" value="${esc(tg.bot_token || '')}" placeholder="123456:ABC-DEF"></div>
                <div class="field"><label>${t('alarm.tg_chat')}</label><input name="tg_chat" class="mono" value="${esc(tg.chat_id || '')}"></div>
            </div>
            <div class="field"><label>${t('alarm.webhook')}</label><input name="webhook" class="mono" value="${esc(ch.webhook || '')}" placeholder="https://…"></div>
            <h2>${t('alarm.thresholds')}</h2>
            <div class="grid cols-3">
                <div class="field"><label>CPU %</label><input name="cpu_pct" type="number" min="0" max="100" class="mono" value="${Number(thr.cpu_pct ?? 90)}"></div>
                <div class="field"><label>RAM %</label><input name="mem_pct" type="number" min="0" max="100" class="mono" value="${Number(thr.mem_pct ?? 90)}"></div>
                <div class="field"><label>Disk %</label><input name="disk_pct" type="number" min="0" max="100" class="mono" value="${Number(thr.disk_pct ?? 90)}"></div>
            </div>
            <div class="dialog-foot">
                <button type="button" class="btn" id="alarmtest">${t('alarm.test')}</button>
                <span class="spacer"></span>
                <button type="button" class="btn" data-close>${t('common.cancel')}</button>
                <button class="btn primary">${t('common.save')}</button>
            </div>
        </form>`, { wide: true });

    modal.querySelector('#alarmtest').addEventListener('click', async () => {
        try { const r = await api('/monitoring/alarms/test', { method: 'POST' });
            toast(r.enabled && r.channels.length ? `${t('alarm.test_sent')}: ${r.channels.join(', ')}` : t('alarm.test_none'), r.channels.length ? 'ok' : 'warn');
        } catch (err) { toast(err.message, 'err'); }
    });
    modal.querySelector('#alf').addEventListener('submit', async (e) => {
        e.preventDefault();
        const f = new FormData(e.target);
        const body = {
            enabled: f.get('enabled') === 'on',
            channels: {
                email: f.get('email'), webhook: f.get('webhook'),
                telegram: { bot_token: f.get('tg_token'), chat_id: f.get('tg_chat') },
            },
            thresholds: { cpu_pct: f.get('cpu_pct'), mem_pct: f.get('mem_pct'), disk_pct: f.get('disk_pct') },
        };
        try { await api('/monitoring/alarms', { method: 'PUT', body }); modal.close(); toast(t('alarm.saved')); }
        catch (err) { toast(err.message, 'err'); }
    });
}

// ---------------------------------------------------------------- Cloudflare
async function pageCloudflare() {
    setActive('protect');
    main().innerHTML = `${tabsHtml('protect', 'cloudflare')}<div class="empty">${t('common.loading')}</div>`;
    const acct = await api('/cloudflare/account').catch(() => ({ connected: false, accounts: [] }));
    const accounts = acct.accounts || [];

    main().innerHTML = `${tabsHtml('protect', 'cloudflare')}
    <div class="card" style="max-width:640px">
        <div class="card-head"><h2>${t('cf.accounts')}</h2></div>
        ${accounts.length ? `<table class="data"><tbody>${accounts.map((a) => `<tr>
            <td class="mono" style="font-weight:600">${esc(a.name)}</td>
            <td><span class="badge ${a.status === 'active' ? 'ok' : 'warn'}">${esc(a.status)}</span>${Number(a.owned) ? '' : ` <span class="badge">${t('cf.linked')}</span>`}</td>
            <td class="num">${Number(a.owned) ? `<button class="btn danger sm" data-cfdel="${a.id}">${t('common.delete')}</button>` : ''}</td>
        </tr>`).join('')}</tbody></table>` : `<p class="hint" style="margin:0 0 var(--gap)">${t('cf.intro')}</p>`}
        <form id="cff" class="addform">
            <div class="addform-h">${t('cf.add_account')}</div>
            <div class="addform-grid">
                <div class="field"><label>${t('cf.name')}</label><input name="name" class="mono" placeholder="npr. Glavni CF"></div>
                <div class="field"><label>${t('cf.token')}</label><input name="api_token" required class="mono" placeholder="cfat_…" autocomplete="off"></div>
            </div>
            <div class="addform-foot"><button class="btn primary">${icon('cloud')}${t('cf.connect')}</button></div>
        </form>
    </div>
    ${accounts.length ? `
    <div class="card mt">
        <div class="card-head"><h2>${t('cf.zones')}</h2><span class="spacer"></span>
            ${accounts.length > 1 ? `<select id="cfacct" class="mono">${accounts.map((a) => `<option value="${a.id}">${esc(a.name)}</option>`).join('')}</select>` : ''}</div>
        <div id="cfzonebox">${t('common.loading')}</div>
    </div>` : ''}`;

    main().querySelector('#cff').addEventListener('submit', async (e) => {
        e.preventDefault();
        try { await api('/cloudflare/account', { method: 'POST', body: Object.fromEntries(new FormData(e.target)) }); toast(t('cf.connected'), 'ok'); pageCloudflare(); }
        catch (err) { toast(err.message, 'err'); }
    });
    main().querySelectorAll('[data-cfdel]').forEach((b) => b.addEventListener('click', async () => {
        if (!confirm(t('common.confirm_delete'))) return;
        try { await api(`/cloudflare/account/${b.dataset.cfdel}`, { method: 'DELETE' }); pageCloudflare(); }
        catch (err) { toast(err.message, 'err'); }
    }));
    if (!accounts.length) return;

    const loadZones = async (accountId) => {
        const box = document.getElementById('cfzonebox');
        box.innerHTML = t('common.loading');
        const [zones, vhosts] = await Promise.all([
            api('/cloudflare/zones' + (accountId ? `?account_id=${accountId}` : '')).catch(() => []),
            api('/vhosts').catch(() => []),
        ]);
        // Pridruži svakoj domeni ISPRAVNU zonu na ovom računu (apex = ime zone, poddomena
        // = sufiks); najspecifičnija (najduže ime) pobjeđuje. Domene bez zone na ovom računu
        // se NE prikazuju — nema krivog povezivanja (npr. avallus.hr → forgepanel.online).
        const matchZone = (domain) => {
            const d = domain.toLowerCase();
            let best = null;
            for (const z of zones) {
                const n = z.name.toLowerCase();
                if ((d === n || d.endsWith('.' + n)) && (!best || n.length > best.name.length)) best = z;
            }
            return best;
        };
        const syncable = vhosts.map((v) => ({ v, zone: matchZone(v.domain) })).filter((x) => x.zone);
        box.innerHTML = `
        ${zones.length ? `<table class="data"><thead><tr><th>${t('cf.zone')}</th><th>${t('cf.status')}</th><th class="mono hide-sm">Zone ID</th></tr></thead>
        <tbody>${zones.map((z) => `<tr><td class="mono">${esc(z.name)}</td>
            <td><span class="badge ${z.status === 'active' ? 'ok' : 'warn'}">${esc(z.status)}</span></td>
            <td class="mono hide-sm" style="font-size:var(--fs-sm)">${esc(z.id)}</td></tr>`).join('')}</tbody></table>` : `<div class="empty">${t('cf.zones')}: 0</div>`}
        ${zones.length ? `<div class="card-head mt"><h2>${t('cf.sync')}</h2></div>
        ${syncable.length ? `<table class="data"><thead><tr><th>${t('vhost.domain')}</th><th>${t('cf.zone')}</th><th></th></tr></thead>
        <tbody>${syncable.map(({ v, zone }) => `<tr data-vid="${v.id}" data-zone="${esc(zone.id)}">
            <td class="mono">${esc(v.domain)}</td>
            <td><span class="mono">${esc(zone.name)}</span>
                <label class="inline" style="margin-left:8px"><input type="checkbox" class="cf-proxy" checked> ${t('cf.proxy')}</label></td>
            <td class="num"><button class="btn cf-sync">${t('cf.sync')}</button><button class="btn cf-purge">${t('cf.purge')}</button></td>
        </tr>`).join('')}</tbody></table>` : `<div class="empty">${t('cf.no_syncable')}</div>`}` : ''}`;

        box.querySelectorAll('tr[data-vid]').forEach((tr) => {
            const vid = tr.dataset.vid;
            tr.querySelector('.cf-sync').addEventListener('click', async (e) => {
                e.target.disabled = true;
                try {
                    const r = await api(`/vhosts/${vid}/cloudflare/sync`, { method: 'POST',
                        body: { zone_id: tr.dataset.zone, proxy: tr.querySelector('.cf-proxy').checked, account_id: accountId } });
                    toast(t('cf.sync_done') + ': ' + (r.created || []).join(', '), 'ok');
                } catch (err) { toast(err.message, 'err'); } finally { e.target.disabled = false; }
            });
            tr.querySelector('.cf-purge').addEventListener('click', async (e) => {
                e.target.disabled = true;
                try { await api(`/vhosts/${vid}/cloudflare/purge`, { method: 'POST', body: {} }); toast(t('cf.purge_done'), 'ok'); }
                catch (err) { toast(err.message, 'err'); } finally { e.target.disabled = false; }
            });
        });
    };
    const acctSel = document.getElementById('cfacct');
    loadZones(acctSel ? Number(acctSel.value) : accounts[0].id);
    acctSel?.addEventListener('change', () => loadZones(Number(acctSel.value)));
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

    const dnsStatus = await api('/dns/status').catch(() => ({ installed: true }));
    if (!dnsStatus.installed) {
        document.getElementById('zones').innerHTML = `
            <div class="alert warn" style="display:flex;align-items:center;gap:10px">
                <span style="flex:1">${t('dns.not_installed')}</span>
                ${state.me.role === 'admin' ? `<button class="btn sm" id="dnsinstall">${icon('download')}${t('dns.install')}</button>` : ''}</div>`;
        document.getElementById('dnsinstall')?.addEventListener('click', async (e) => {
            e.target.disabled = true;
            try { const r = await api('/dns/install', { method: 'POST' }); watchTask(r.task_id, 'DNS (BIND9) install'); toast(t('dns.installing')); }
            catch (err) { toast(err.message, 'err'); e.target.disabled = false; }
        });
        return;
    }

    const zones = await api('/dns/zones');
    // poddomenske zone (npr. radnovrijeme.avallus.org) gnijezde se ispod matične zone;
    // njihovi zapisi su ionako dio matične zone pa nemaju CF export
    const zdomains = new Set(zones.map((z) => z.domain));
    const zoneParent = (z) => {
        const parts = z.domain.split('.');
        for (let i = 1; i < parts.length - 1; i++) {
            const cand = parts.slice(i).join('.');
            if (zdomains.has(cand)) return cand;
        }
        return null;
    };
    const sortedZones = [...zones].sort((a, b) => {
        const ra = zoneParent(a) || a.domain, rb = zoneParent(b) || b.domain;
        if (ra !== rb) return ra < rb ? -1 : 1;
        const ca = zoneParent(a) ? 1 : 0, cb = zoneParent(b) ? 1 : 0;
        if (ca !== cb) return ca - cb;
        return a.domain < b.domain ? -1 : 1;
    });
    document.getElementById('zones').innerHTML = zones.length ? `
        <table class="data"><thead><tr><th>${t('dns.zone')}</th><th class="hide-sm">Serial</th><th>DNSSEC</th><th>Cloudflare</th><th></th></tr></thead><tbody>
        ${sortedZones.map((z) => {
            const child = zoneParent(z) !== null;
            return `<tr class="row-link ${child ? 'child' : ''}" data-zone="${z.id}" data-domain="${esc(z.domain)}" data-child="${child ? '1' : '0'}">
            <td class="mono"><span class="zone-name${child ? ' nested' : ''}">${esc(z.domain)}</span></td>
            <td class="mono hide-sm">${esc(z.serial)}</td>
            <td><span class="badge ${Number(z.dnssec_enabled) ? 'ok' : ''}">${Number(z.dnssec_enabled) ? 'on' : 'off'}</span></td>
            <td>${child ? '<span class="mono" style="color:var(--ink-3)">—</span>' : (z.cf_account ? `<span class="badge info">${icon('cloud', 12)} ${esc(z.cf_account)}</span>` : '<span class="mono" style="color:var(--ink-3)">—</span>')}</td>
            <td class="num">
                ${child ? '' : `<button class="btn ghost" data-cfexp="${z.id}" data-domain="${esc(z.domain)}">${icon('cloud')}${t('dns.cf_export')}</button>`}
                <button class="btn danger" data-delzone="${z.id}">${t('common.delete')}</button></td>
        </tr>`;
        }).join('')}</tbody></table>` : `<div class="empty">${t('nav.dns')}: 0</div>`;

    main().querySelectorAll('[data-cfexp]').forEach((b) => b.addEventListener('click', (e) => {
        e.stopPropagation();
        exportZoneToCloudflare(Number(b.dataset.cfexp), b.dataset.domain);
    }));

    main().querySelectorAll('[data-delzone]').forEach((b) => b.addEventListener('click', async (e) => {
        e.stopPropagation();
        if (!confirm(t('common.confirm_delete'))) return;
        try { await api(`/dns/zones/${b.dataset.delzone}`, { method: 'DELETE' }); pageDns(); }
        catch (err) { toast(err.message, 'err'); }
    }));
    main().querySelectorAll('[data-zone]').forEach((tr) => tr.addEventListener('click', () =>
        dnsRecords(Number(tr.dataset.zone), tr.dataset.domain, tr.dataset.child === '1')));
}

async function dnsRecords(zoneId, domain, isChild = false) {
    const container = document.getElementById('records');
    container.innerHTML = `<div class="card mt">${t('common.loading')}</div>`;
    const records = await api(`/dns/zones/${zoneId}/records`);

    container.innerHTML = `
    <div class="card mt">
        <div class="page-head"><h2 class="mono">${esc(domain)}</h2><div class="spacer"></div>
            ${isChild ? '' : `<button class="btn" id="cfexport">${icon('cloud')}${t('dns.cf_export')}</button>`}</div>
        <table class="data"><thead><tr>
            <th>${t('dns.name')}</th><th>Tip</th><th>${t('dns.content')}</th><th class="hide-sm">TTL</th><th class="hide-sm">Prio</th><th></th>
        </tr></thead><tbody>
        ${records.map((r) => `<tr>
            <td class="mono">${esc(r.name)}</td>
            <td class="mono">${esc(r.type)}</td>
            <td class="mono" style="word-break:break-all">${esc(r.content)}</td>
            <td class="mono hide-sm">${r.ttl}</td>
            <td class="mono hide-sm">${r.prio ?? ''}</td>
            <td class="num">
                <button class="btn ghost" data-editrec="${r.id}">${t('common.edit')}</button>
                <button class="btn danger" data-delrec="${r.id}">${t('common.delete')}</button></td>
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
            dnsRecords(zoneId, domain, isChild);
        } catch (err) { toast(err.message, 'err'); }
    });
    container.querySelectorAll('[data-delrec]').forEach((b) => b.addEventListener('click', async () => {
        if (!confirm(t('common.confirm_delete'))) return;
        try { await api(`/dns/zones/${zoneId}/records/${b.dataset.delrec}`, { method: 'DELETE' }); dnsRecords(zoneId, domain, isChild); }
        catch (err) { toast(err.message, 'err'); }
    }));
    container.querySelectorAll('[data-editrec]').forEach((b) => b.addEventListener('click', () =>
        editDnsRecordModal(zoneId, domain, records.find((r) => String(r.id) === b.dataset.editrec))));
    container.querySelector('#cfexport')?.addEventListener('click', () => exportZoneToCloudflare(zoneId, domain));
}

// Export zone na Cloudflare — bira račun ako ih ima više
async function exportZoneToCloudflare(zoneId, domain) {
    let accounts = [];
    try { accounts = (await api('/cloudflare/account')).accounts || []; } catch { /* nije povezan */ }
    if (!accounts.length) return toast(t('dns.cloudflare_not_connected'), 'err');
    const run = async (accountId) => {
        try {
            const r = await api(`/dns/zones/${zoneId}/cloudflare/export`, { method: 'POST', body: accountId ? { account_id: accountId } : {} });
            const extra = r.failed?.length ? ` · ${t('dns.cf_failed')}: ${r.failed.length}` : '';
            toast(`${t('dns.cf_export_done')} · +${r.created} · ${t('dns.cf_skipped')}: ${r.skipped}${extra}`, r.failed?.length ? 'warn' : 'ok');
        } catch (err) { toast(t('dns.' + err.message) !== 'dns.' + err.message ? t('dns.' + err.message) : err.message, 'err'); }
    };
    if (accounts.length === 1) return run(accounts[0].id);
    const modal = openModal(`
        <div class="dialog-head"><h1>${t('dns.cf_export')} <span class="mono">${esc(domain)}</span></h1><button class="btn ghost icon" data-close>${icon('x')}</button></div>
        <form id="cxf">
            <div class="field"><label>${t('cf.account')}</label>
                <select name="account_id" class="mono">${accounts.map((a) => `<option value="${a.id}">${esc(a.name)}</option>`).join('')}</select></div>
            <div class="dialog-foot"><button type="button" class="btn" data-close>${t('common.cancel')}</button>
                <button class="btn primary">${t('dns.cf_export')}</button></div>
        </form>`);
    modal.querySelector('#cxf').addEventListener('submit', async (e) => {
        e.preventDefault();
        const accountId = Number(new FormData(e.target).get('account_id'));
        modal.close(); await run(accountId);
    });
}

function editDnsRecordModal(zoneId, domain, rec) {
    const types = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];
    const modal = openModal(`
        <div class="dialog-head"><h1>${t('dns.edit_record')} <span class="mono">${esc(domain)}</span></h1>
            <button class="btn ghost icon" data-close>${icon('x')}</button></div>
        <form id="erf">
            <div class="grid cols-2">
                <div class="field"><label>${t('dns.name')}</label><input name="name" class="mono" value="${esc(rec.name)}"></div>
                <div class="field"><label>Tip</label><select name="type" class="mono">${types.map((x) => `<option ${x === rec.type ? 'selected' : ''}>${x}</option>`).join('')}</select></div>
            </div>
            <div class="field"><label>${t('dns.content')}</label><input name="content" required class="mono" value="${esc(rec.content)}"></div>
            <div class="grid cols-2">
                <div class="field"><label>TTL</label><input name="ttl" class="mono" value="${rec.ttl}"></div>
                <div class="field"><label>Prio</label><input name="prio" class="mono" placeholder="—" value="${rec.prio ?? ''}"></div>
            </div>
            <div class="dialog-foot"><button type="button" class="btn" data-close>${t('common.cancel')}</button>
                <button class="btn primary">${t('common.save')}</button></div>
        </form>`);
    modal.querySelector('#erf').addEventListener('submit', async (e) => {
        e.preventDefault();
        const body = Object.fromEntries(new FormData(e.target));
        if (!body.prio) delete body.prio;
        try {
            await api(`/dns/zones/${zoneId}/records/${rec.id}`, { method: 'PUT', body });
            modal.close(); dnsRecords(zoneId, domain);
        } catch (err) { toast(err.message, 'err'); }
    });
}

// ---------------------------------------------------------------- mail
async function pageMail() {
    setActive('mail');
    main().innerHTML = `<div class="empty">${t('common.loading')}</div>`;

    const status = await api('/mail/status');
    if (!status.installed) {
        main().innerHTML = `
        ${tabsHtml('mail', 'mail')}
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
    ${tabsHtml('mail', 'mail')}
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
    <div class="grid cols-2 mt top">
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

// ---------------------------------------------------------------- deliverability (mail health)
async function pageDeliverability() {
    setActive('deliverability');
    const isAdmin = state.me.role === 'admin';
    main().innerHTML = `${tabsHtml('mail', 'deliverability')}<div class="empty">${t('common.loading')}</div>`;

    const [domains, dmarc] = await Promise.all([
        api('/mail/domains').catch(() => []),
        api('/deliverability/dmarc').catch(() => []),
    ]);

    main().innerHTML = `${tabsHtml('mail', 'deliverability')}
    <div class="grid cols-2 top">
        <div class="card" id="validator">
            <div class="card-head"><h2>${icon('check')}${t('deliver.validator')}</h2></div>
            <p class="hint">${t('deliver.validator_hint')}</p>
            <form id="valf" class="row" style="gap:8px;align-items:flex-end">
                <div class="field" style="flex:1;margin:0"><label>${t('vhost.domain')}</label>
                    <input name="domain" required class="mono" list="maildoms" placeholder="example.com"
                        value="${esc(domains[0]?.domain ?? '')}"></div>
                <button class="btn primary">${icon('search')}${t('deliver.check')}</button>
            </form>
            <datalist id="maildoms">${domains.map((d) => `<option value="${esc(d.domain)}">`).join('')}</datalist>
            <div id="valres" class="mt"></div>
        </div>
        ${isAdmin ? `<div class="card" id="rblcard">
            <div class="card-head"><h2>${icon('shield')}${t('deliver.rbl')}</h2>
                <button class="btn sm" id="rblnow">${icon('refresh')}${t('deliver.check_now')}</button></div>
            <p class="hint">${t('deliver.rbl_hint')}</p>
            <div id="rblres">${t('common.loading')}</div>
        </div>` : `<div class="card"><div class="empty">${t('deliver.rbl_admin_only')}</div></div>`}
    </div>

    <div class="card mt" id="dmarccard">
        <div class="card-head"><h2>${icon('activity')}${t('deliver.dmarc')}</h2>
            ${isAdmin ? `<button class="btn sm" id="dmarcingest">${icon('download')}${t('deliver.ingest')}</button>` : ''}</div>
        <p class="hint">${t('deliver.dmarc_hint')}</p>
        ${dmarcHtml(dmarc)}
    </div>

    ${isAdmin ? `<div class="card mt" id="queuecard">
        <div class="card-head"><h2>${icon('mail')}${t('deliver.queue')}</h2>
            <span class="spacer"></span>
            <button class="btn sm" id="qflush">${icon('play')}${t('deliver.flush')}</button>
            <button class="btn sm danger" id="qdelall">${t('deliver.delete_all')}</button></div>
        <p class="hint">${t('deliver.queue_hint')}</p>
        <div id="queueres">${t('common.loading')}</div>
    </div>` : ''}`;

    // Validator (wizard)
    document.getElementById('valf').addEventListener('submit', async (e) => {
        e.preventDefault();
        const domain = new FormData(e.target).get('domain');
        const box = document.getElementById('valres');
        box.innerHTML = `<div class="empty">${t('common.loading')}</div>`;
        try {
            const r = await api('/deliverability/validate', { method: 'POST', body: { domain } });
            box.innerHTML = validatorHtml(r.validation);
        } catch (err) { box.innerHTML = `<div class="alert err">${esc(err.message)}</div>`; }
    });

    if (isAdmin) {
        document.getElementById('rblnow').addEventListener('click', async (e) => {
            e.target.disabled = true;
            document.getElementById('rblres').innerHTML = `<div class="empty">${t('common.loading')}</div>`;
            try { await api('/deliverability/rbl'); } catch (err) { toast(err.message, 'err'); }
            await loadRbl();
            e.target.disabled = false;
        });
        document.getElementById('dmarcingest').addEventListener('click', async (e) => {
            e.target.disabled = true;
            try {
                const r = await api('/deliverability/dmarc/ingest', { method: 'POST' });
                toast(`${t('deliver.ingested')}: ${r.ingested} / ${r.scanned}`);
                pageDeliverability();
            } catch (err) { toast(err.message, 'err'); e.target.disabled = false; }
        });
        document.getElementById('qflush').addEventListener('click', async () => {
            try { renderQueue(await api('/deliverability/queue/flush', { method: 'POST' })); toast(t('deliver.flushed')); }
            catch (err) { toast(err.message, 'err'); }
        });
        document.getElementById('qdelall').addEventListener('click', async () => {
            if (!confirm(t('deliver.confirm_delete_all'))) return;
            try { renderQueue(await api('/deliverability/queue/delete-all', { method: 'POST' })); }
            catch (err) { toast(err.message, 'err'); }
        });
        loadRbl();
        loadQueue();
    }
}

function validatorHtml(v) {
    const row = (label, ok, detail, fix) => `
        <div class="deliver-check">
            <span class="badge ${ok ? 'ok' : 'err'}">${ok ? t('deliver.pass') : t('deliver.fail')}</span>
            <div><b>${label}</b>${detail ? `<div class="mono small">${esc(detail)}</div>` : ''}
                ${fix ? `<div class="hint">${icon('info')}${esc(fix)}</div>` : ''}</div>
        </div>`;
    const spf = v.spf, dkim = v.dkim, dmarc = v.dmarc;
    return `
        ${row('SPF', spf.found && !spf.issue, spf.record, spf.found ? (spf.issue ?? null) : t('deliver.spf_missing'))}
        ${row('DKIM', dkim.found, dkim.found ? 'forge._domainkey' : null, dkim.found ? null : t('deliver.dkim_missing'))}
        ${row('DMARC' + (dmarc.policy ? ` · p=${esc(dmarc.policy)}` : ''), dmarc.found && dmarc.policy !== 'none', dmarc.record,
            !dmarc.found ? t('deliver.dmarc_missing') : (dmarc.policy === 'none' ? t('deliver.dmarc_none') : null))}`;
}

function dmarcHtml(reports) {
    if (!reports.length) return `<div class="empty">${t('deliver.dmarc_empty')}</div>`;
    return reports.map((rep) => {
        const p = typeof rep.parsed === 'string' ? JSON.parse(rep.parsed) : rep.parsed;
        const rows = p.rows ?? [];
        let pass = 0, fail = 0;
        rows.forEach((r) => { const c = Number(r.count) || 0; (r.dkim === 'pass' || r.spf === 'pass') ? pass += c : fail += c; });
        const total = pass + fail || 1;
        return `<div class="dmarc-report">
            <div class="row" style="justify-content:space-between"><b class="mono">${esc(rep.domain)}</b>
                <span class="small">${esc(rep.org)} · ${esc(rep.date_range)}</span></div>
            <div class="meter"><span class="meter-ok" style="width:${(pass / total * 100).toFixed(0)}%"></span>
                <span class="meter-bad" style="width:${(fail / total * 100).toFixed(0)}%"></span></div>
            <div class="small">${t('deliver.aligned')}: <b style="color:var(--ok)">${pass}</b> ·
                ${t('deliver.unaligned')}: <b style="color:var(--err)">${fail}</b></div>
            ${fail > 0 ? `<table class="data mt"><thead><tr><th>IP</th><th class="num">${t('deliver.count')}</th><th>DKIM</th><th>SPF</th><th>${t('deliver.disposition')}</th></tr></thead><tbody>
                ${rows.filter((r) => r.dkim !== 'pass' && r.spf !== 'pass').slice(0, 8).map((r) => `<tr>
                    <td class="mono">${esc(r.source_ip)}</td><td class="num mono">${Number(r.count) || 0}</td>
                    <td><span class="badge ${r.dkim === 'pass' ? 'ok' : 'err'}">${esc(r.dkim)}</span></td>
                    <td><span class="badge ${r.spf === 'pass' ? 'ok' : 'err'}">${esc(r.spf)}</span></td>
                    <td class="mono">${esc(r.disposition)}</td></tr>`).join('')}</tbody></table>` : ''}
        </div>`;
    }).join('');
}

async function loadRbl() {
    const box = document.getElementById('rblres');
    if (!box) return;
    const data = await api('/deliverability/rbl/history').catch(() => ({ ip: '', results: [] }));
    const listed = data.results.filter((r) => r.listed);
    box.innerHTML = `
        <div class="row" style="gap:8px;align-items:center">
            <span class="mono">${esc(data.ip || '—')}</span>
            ${data.results.length
                ? (listed.length
                    ? `<span class="badge err">${listed.length} ${t('deliver.listed')}</span>`
                    : `<span class="badge ok">${t('deliver.clean')}</span>`)
                : `<span class="badge warn">${t('deliver.not_checked')}</span>`}
            ${data.results[0]?.checked_at ? `<span class="small" style="margin-left:auto">${fmtDate(data.results[0].checked_at)}</span>` : ''}
        </div>
        ${listed.length ? `<div class="mt">${listed.map((r) => `<div class="mono small" style="color:var(--err)">${icon('x')}${esc(r.rbl)}</div>`).join('')}</div>` : ''}`;
}

async function loadQueue() {
    try { renderQueue(await api('/deliverability/queue')); }
    catch (err) { const b = document.getElementById('queueres'); if (b) b.innerHTML = `<div class="alert err">${esc(err.message)}</div>`; }
}

function renderQueue(q) {
    const box = document.getElementById('queueres');
    if (!box) return;
    const byq = Object.entries(q.by_queue || {}).map(([k, v]) => `${esc(k)}: ${v}`).join(' · ');
    box.innerHTML = `
        <div class="row" style="gap:8px;align-items:center"><span class="badge ${q.total ? 'warn' : 'ok'}">${q.total} ${t('deliver.in_queue')}</span>
            ${byq ? `<span class="small">${byq}</span>` : ''}</div>
        ${q.messages?.length ? `<table class="data mt"><thead><tr><th>ID</th><th>${t('deliver.sender')}</th><th class="hide-sm">${t('deliver.recipient')}</th><th class="hide-sm">${t('deliver.reason')}</th><th></th></tr></thead><tbody>
            ${q.messages.map((m) => `<tr>
                <td class="mono small">${esc(m.queue_id)}</td>
                <td class="mono small">${esc(m.sender || '—')}</td>
                <td class="mono small hide-sm">${esc((m.recipients || []).join(', ').slice(0, 60))}</td>
                <td class="small hide-sm">${esc((m.reason || '').slice(0, 50))}</td>
                <td class="num"><button class="btn sm danger" data-qdel="${esc(m.queue_id)}">${t('common.delete')}</button></td>
            </tr>`).join('')}</tbody></table>` : `<div class="empty mt">${t('deliver.queue_empty')}</div>`}`;
    box.querySelectorAll('[data-qdel]').forEach((b) => b.addEventListener('click', async () => {
        try { renderQueue(await api(`/deliverability/queue/${b.dataset.qdel}`, { method: 'DELETE' })); }
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
    <div id="quota"></div>
    <div class="card" id="list">${t('common.loading')}</div>
    <div class="card mt"><h2>${t('users.plans')}</h2><div id="plans">${t('common.loading')}</div></div>`;

    document.getElementById('brand').addEventListener('click', brandingModal);

    const [users, plans] = await Promise.all([api('/users'), api('/plans')]);
    const vhosts = state.me.role === 'admin' ? await api('/vhosts').catch(() => []) : [];
    document.getElementById('newu').addEventListener('click', () => userModal(null, plans, vhosts));

    // Reseller: prikaz vlastite kvote (paket vs. raspodijeljeno klijentima)
    if (state.me.role === 'reseller') {
        const q = await api('/reseller/quota').catch(() => null);
        if (q && q.ceiling) document.getElementById('quota').innerHTML = resellerQuotaCard(q);
    }
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
            </td></tr>`).join('')}</tbody></table>` : `<div class="empty">${t(state.me.role === 'reseller' ? 'users.empty_clients' : 'users.empty')}</div>`;

    const canEditPlan = (p) => state.me.role === 'admin' || p.owner_user_id != null;
    const planRow = (p) => `<tr>
            <td class="mono">${esc(p.name)}</td>
            <td class="mono">${fmtBytes(p.disk_bytes)} · ${p.max_domains} domena · ${p.max_mailboxes} mail · ${p.max_databases} baza</td>
            <td class="mono">${(JSON.parse(p.php_versions || '[]')).join(', ')}</td>
            <td class="num">${canEditPlan(p) ? `
                <button class="btn ghost" data-pedit="${p.id}">${t('common.edit')}</button>
                <button class="btn danger" data-pdel="${p.id}">${t('common.delete')}</button>` : ''}</td>
        </tr>`;
    const planTable = (arr) => `<table class="data"><tbody>${arr.map(planRow).join('') || `<tr><td><div class="empty">0</div></td></tr>`}</tbody></table>`;
    const hostingPlans = plans.filter((p) => !isResellerPlan(p));
    const resellerPkgs = plans.filter(isResellerPlan);
    document.getElementById('plans').innerHTML = `
        ${planTable(hostingPlans)}
        <button class="btn mt" id="newplan">${icon('plus')}${t('users.new_plan')}</button>
        ${state.me.role === 'admin' ? `
            <h3 class="subhead">${t('users.reseller_pkgs')}</h3>
            ${planTable(resellerPkgs)}
            <button class="btn mt" id="newpkg">${icon('plus')}${t('users.new_pkg')}</button>` : ''}`;
    document.getElementById('newplan').addEventListener('click', () => planModal());
    document.getElementById('newpkg')?.addEventListener('click', () => planModal(null, true));
    main().querySelectorAll('[data-pedit]').forEach((b) => b.addEventListener('click', () => planModal(plans.find((p) => String(p.id) === b.dataset.pedit))));
    main().querySelectorAll('[data-pdel]').forEach((b) => b.addEventListener('click', async () => {
        if (!confirm(t('common.confirm_delete'))) return;
        try { await api(`/plans/${b.dataset.pdel}`, { method: 'DELETE' }); pageUsers(); }
        catch (err) { toast(err.message, 'err'); }
    }));

    const userErr = (err) => toast(t('users.' + err.message) !== 'users.' + err.message ? t('users.' + err.message) : err.message, 'err');
    main().querySelectorAll('[data-toggle]').forEach((b) => b.addEventListener('click', async () => {
        try { await api(`/users/${b.dataset.toggle}/status`, { method: 'PUT', body: { status: b.dataset.status === 'active' ? 'suspended' : 'active' } }); pageUsers(); }
        catch (err) { userErr(err); }
    }));
    main().querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () => userModal(users.find((u) => String(u.id) === b.dataset.edit), plans, vhosts)));
    main().querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', async () => {
        if (!confirm(t('common.confirm_delete'))) return;
        try { await api(`/users/${b.dataset.del}`, { method: 'DELETE' }); pageUsers(); }
        catch (err) { userErr(err); }
    }));
}

function planFeatures(p) { try { return JSON.parse(p.features || '{}') || {}; } catch { return {}; } }
const isResellerPlan = (p) => planFeatures(p).reseller === true;

// reseller kvota: paket (ceiling) vs. raspodijeljeno klijentima (allocated)
function resellerQuotaCard(q) {
    const c = q.ceiling, used = q.allocated, own = q.own || {}, cli = q.clients || {};
    const bar = (label, key, fmt = (x) => x) => {
        const u = Number(used[key] || 0), limit = Number(c[key] || 0);
        const pct = limit > 0 ? Math.min(100, Math.round(u / limit * 100)) : 0;
        const tone = pct >= 90 ? 'meter-bad' : (pct >= 75 ? 'meter-warn' : 'meter-ok');
        const o = Number(own[key] || 0), cl = Number(cli[key] || 0);
        // razlomi na "tvoje" vs "klijenti" samo kad oboje doprinose
        const split = (o > 0 && cl > 0)
            ? `<span class="quota-split">${fmt(o)} ${t('reseller.own')} · ${fmt(cl)} ${t('reseller.clients')}</span>` : '';
        return `<div class="usage-item"><div class="row" style="justify-content:space-between">
            <span>${label}${split}</span><span class="mono small">${fmt(u)} / ${fmt(limit)}</span></div>
            <div class="meter"><span class="${tone}" style="width:${pct}%"></span></div></div>`;
    };
    return `<div class="card" style="margin-bottom:var(--gap)">
        <div class="card-head"><h2>${t('reseller.quota')}</h2></div>
        <p class="hint">${t('reseller.quota_hint')}</p>
        ${bar(t('nav.websites'), 'max_domains')}
        ${bar(t('dash.mailboxes'), 'max_mailboxes')}
        ${bar(t('nav.databases'), 'max_databases')}
        ${bar(t('dash.disk'), 'disk_bytes', fmtBytes)}
    </div>`;
}

function userModal(user = null, plans = [], vhosts = []) {
    const isAdmin = state.me.role === 'admin';
    const edit = user != null;
    const clientPlans = plans.filter((p) => !isResellerPlan(p));
    const resellerPlans = plans.filter((p) => isResellerPlan(p));
    const curPlan = edit && user.plan_id != null ? String(user.plan_id) : '';
    const planOpts = (arr) => `<option value="">${t('users.no_plan')}</option>` +
        arr.map((p) => `<option value="${p.id}" ${String(p.id) === curPlan ? 'selected' : ''}>${esc(p.name)} — ${p.max_domains}d/${p.max_databases}b/${p.max_mailboxes}m</option>`).join('');
    // Plesk-style "Access to subscription": admin bira koje postojeće stranice korisnik ima
    const assigned = new Set((edit && Array.isArray(user.vhost_ids) ? user.vhost_ids : []).map(Number));
    const siteAccess = (isAdmin && vhosts.length) ? `
            <div class="field" id="vhwrap">
                <label>${t('users.site_access')}</label>
                <div class="checkrow vh-access">${vhosts.map((v) =>
                    `<label class="chk"><input type="checkbox" name="vhost_ids" value="${v.id}" ${assigned.has(Number(v.id)) ? 'checked' : ''}> ${esc(v.domain)}</label>`).join('')}</div>
                <span class="hint">${t('users.site_access_hint')}</span></div>` : '';
    const modal = openModal(`
        <div class="dialog-head"><h1>${edit ? t('users.edit') : t('users.new')}</h1><button class="btn ghost icon" data-close>${icon('x')}</button></div>
        <form id="uf">
            <div class="field"><label>${t('auth.email')}</label><input name="email" type="email" required class="mono" value="${edit ? esc(user.email) : ''}"></div>
            <div class="field"><label>${t('auth.password')}</label><input name="password" type="password" ${edit ? '' : 'required'} minlength="12" placeholder="${edit ? t('users.password_keep') : ''}"></div>
            <div class="field"><label>Rola</label><select name="role" id="urole" ${edit && !isAdmin ? 'disabled' : ''}>
                <option value="client" ${edit && user.role === 'client' ? 'selected' : ''}>client</option>
                ${isAdmin ? `<option value="reseller" ${edit && user.role === 'reseller' ? 'selected' : ''}>reseller</option><option value="admin" ${edit && user.role === 'admin' ? 'selected' : ''}>admin</option>` : ''}
            </select></div>
            <div class="field" id="planwrap">
                <label id="planlabel">${t('users.plan')}</label>
                <select name="plan_id" id="uplan" class="mono">${planOpts(clientPlans)}</select>
                <span class="hint" id="planhint"></span></div>
            ${siteAccess}
            <div class="dialog-foot"><button type="button" class="btn" data-close>${t('common.cancel')}</button>
                <button class="btn primary">${edit ? t('common.save') : t('common.create')}</button></div>
        </form>`);

    // Plan select ovisi o roli: client → klijentski planovi; reseller → reseller paketi
    const roleSel = modal.querySelector('#urole');
    const planWrap = modal.querySelector('#planwrap');
    const vhWrap = modal.querySelector('#vhwrap');
    if (roleSel && planWrap) {
        const syncPlan = () => {
            const r = roleSel.value;
            const uplan = modal.querySelector('#uplan');
            const label = modal.querySelector('#planlabel');
            const hint = modal.querySelector('#planhint');
            if (vhWrap) vhWrap.style.display = r === 'admin' ? 'none' : '';
            if (r === 'admin') { planWrap.style.display = 'none'; return; }
            planWrap.style.display = '';
            if (r === 'reseller') { uplan.innerHTML = planOpts(resellerPlans); label.textContent = t('users.reseller_pkg'); hint.textContent = t('users.reseller_pkg_hint'); }
            else { uplan.innerHTML = planOpts(clientPlans); label.textContent = t('users.plan'); hint.textContent = ''; }
            if (curPlan) uplan.value = curPlan;
        };
        roleSel.addEventListener('change', syncPlan);
        syncPlan();
    }

    modal.querySelector('#uf').addEventListener('submit', async (e) => {
        e.preventDefault();
        const body = Object.fromEntries(new FormData(e.target));
        if (edit && !body.password) delete body.password; // ne mijenjaj lozinku ako je prazna
        if (body.plan_id === '' || body.plan_id == null) delete body.plan_id;
        // Pristup stranicama: pošalji PUNU listu označenih (samo kad polje postoji i rola nije admin)
        if (vhWrap && body.role !== 'admin') {
            body.vhost_ids = [...e.target.querySelectorAll('input[name="vhost_ids"]:checked')].map((c) => Number(c.value));
        } else {
            delete body.vhost_ids;
        }
        try {
            if (edit) await api(`/users/${user.id}`, { method: 'PUT', body });
            else await api('/users', { method: 'POST', body });
            modal.close(); pageUsers();
        } catch (err) { toast(t('users.' + err.message) !== 'users.' + err.message ? t('users.' + err.message) : err.message, 'err'); }
    });
}

function planModal(plan = null, presetReseller = false) {
    const edit = plan != null;
    const isAdmin = state.me.role === 'admin';
    const sel = edit ? JSON.parse(plan.php_versions || '[]') : ['8.4', '8.5'];
    const v = (def, key) => edit ? plan[key] : def;
    const isResellerPkg = edit ? planFeatures(plan).reseller === true : presetReseller;
    const modal = openModal(`
        <div class="dialog-head"><h1>${edit ? t('users.edit_plan') : t('users.new_plan')}</h1><button class="btn ghost icon" data-close>${icon('x')}</button></div>
        <form id="pf">
            <div class="field"><label>Naziv</label><input name="name" required value="${edit ? esc(plan.name) : ''}"></div>
            ${isAdmin ? `<label class="inline" style="gap:8px;margin-bottom:10px"><input type="checkbox" name="reseller" ${isResellerPkg ? 'checked' : ''}> ${t('plan.reseller_pkg')}</label>
                <span class="hint" style="display:block;margin:-6px 0 12px">${t('plan.reseller_pkg_hint')}</span>` : ''}
            <div class="grid cols-2">
                <div class="field"><label>Disk (GB)</label><input name="disk_gb" type="number" value="${edit ? Math.round(plan.disk_bytes / 1073741824) : 10}" class="mono"></div>
                <div class="field"><label>Max domena</label><input name="max_domains" type="number" value="${v(5, 'max_domains')}" class="mono"></div>
                <div class="field"><label>${t('plan.max_subdomains')}</label><input name="max_subdomains" type="number" value="${v(10, 'max_subdomains')}" class="mono"></div>
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
            max_subdomains: Number(f.max_subdomains),
            max_mailboxes: Number(f.max_mailboxes),
            max_databases: Number(f.max_databases),
            php_versions: php,
            reseller: f.reseller === 'on',
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
            <div class="brand-preview" id="bprev">
                <div class="brand-mark" id="pvmark">${b.logo_url ? `<img src="${esc(b.logo_url)}" alt="">` : icon('zap')}</div>
                <div><div class="brand-pv-name" id="pvname">${esc(b.panel_name ?? 'ForgePanel')}</div>
                    <div class="brand-pv-sub mono">${t('brand.preview')}</div></div>
                <button type="button" class="btn sm primary" id="pvbtn" style="margin-left:auto">${t('common.save')}</button>
            </div>
            <div class="grid cols-2">
                <div class="field"><label>${t('brand.name')}</label><input name="panel_name" value="${esc(b.panel_name ?? 'ForgePanel')}"></div>
                <div class="field"><label>${t('brand.accent')}</label><input name="accent" type="color" value="${esc(b.accent ?? '#10b981')}" style="height:38px"></div>
            </div>
            <div class="field"><label>${t('brand.host')}</label><input name="panel_host" class="mono" placeholder="panel.mojadomena.hr" value="${esc(b.panel_host ?? '')}">
                <span class="hint">${t('brand.host_hint')}</span></div>
            <div class="field"><label>Logo URL (https)</label><input name="logo_url" class="mono" placeholder="https://…/logo.svg" value="${esc(b.logo_url ?? '')}"></div>
            <div class="dialog-foot"><button type="button" class="btn" data-close>${t('common.cancel')}</button>
                <button class="btn primary">${t('common.save')}</button></div>
        </form>`, { wide: true });

    const form = modal.querySelector('#bf');
    const prev = modal.querySelector('#bprev');
    // Live preview dok korisnik tipka
    const sync = () => {
        const f = Object.fromEntries(new FormData(form));
        modal.querySelector('#pvname').textContent = f.panel_name || 'ForgePanel';
        prev.style.setProperty('--accent', f.accent);
        modal.querySelector('#pvbtn').style.background = f.accent;
        const mark = modal.querySelector('#pvmark');
        if (f.logo_url && /^https:\/\//.test(f.logo_url)) mark.innerHTML = `<img src="${esc(f.logo_url)}" alt="">`;
        else mark.innerHTML = icon('zap');
    };
    form.addEventListener('input', sync);
    sync();

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const f = Object.fromEntries(new FormData(e.target));
        if (!f.logo_url) delete f.logo_url;
        if (!f.panel_host) delete f.panel_host;
        try {
            const r = await api('/branding', { method: 'PUT', body: f });
            state.branding = r;
            document.documentElement.style.setProperty('--accent', r.accent);
            // Primijeni odmah na shell (logo, naziv, title) — bez reloada.
            // VAŽNO: samo logo .rail-label, ne svi (inače se nazivi nav stavki prepišu nazivom panela).
            document.title = r.panel_name || 'ForgePanel';
            const logoLabel = document.querySelector('.rail-logo .rail-label');
            if (logoLabel) logoLabel.innerHTML = (r.panel_name && r.panel_name !== 'ForgePanel') ? esc(r.panel_name) : 'Forge<b>Panel</b>';
            const railLogo = document.querySelector('.rail-logo');
            if (railLogo) railLogo.title = r.panel_name || 'ForgePanel';
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
const licBadge = (s) => `<span class="badge ${({ active: 'ok', trial: 'ok', suspended: 'warn', expired: 'warn', revoked: 'err' }[s]) || ''}">${esc(s || '—')}</span>`;

async function pageLicensing() {
    setActive('licensing');
    main().innerHTML = `${tabsHtml('server', 'licensing')}<div class="empty">${t('common.loading')}</div>`;
    const [licenses, node, keys, tiersResp, nodes] = await Promise.all([
        api('/licenses').catch(() => []),
        api('/license').catch(() => ({})),
        api('/distribution/keys').catch(() => ({})),
        api('/license/tiers').catch(() => ({ tiers: ['standard', 'pro', 'enterprise'] })),
        api('/licenses/nodes').catch(() => []),
    ]);
    const tiers = tiersResp.tiers || ['standard', 'pro', 'enterprise'];
    main().innerHTML = `${tabsHtml('server', 'licensing')}
    <div class="card"><div class="card-head"><h2>${t('lic.node')}</h2></div>
        <p class="hint" style="margin:0 0 var(--gap)">${t('lic.node_intro')}</p>
        <form id="licf" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
            <div class="field" style="flex:1;min-width:240px;margin:0"><label>${t('lic.key')}</label>
                <input name="license_key" class="mono" value="${esc(node.license_key || '')}" placeholder="FP-XXXX-XXXX-XXXX-XXXX-XXXX"></div>
            <button class="btn primary">${node.configured ? t('lic.recheck') : t('lic.activate')}</button>
        </form>
        ${node.configured ? `<div class="mt">${t('lic.status')}: ${licBadge(node.status)} · <span class="mono">${esc(node.tier || '')}</span>${node.expires_at ? ` · ${t('lic.expires')} ${fmtDate(node.expires_at)}` : ''}</div>
            <div class="hint mt mono">fingerprint: ${esc(node.fingerprint || '')}</div>` : ''}
    </div>
    ${keys.has_key ? `
    <div class="card mt"><div class="card-head"><h2>${icon('server')}${t('lic.nodes')}</h2><span class="count" id="nodecount">${nodes.length} ${t('lic.nodes_count')}</span>
        <span class="spacer"></span>
        <div class="tabs" id="nodefilter">
            <button class="tab active" data-nf="all">${t('lic.f_all')}</button>
            <button class="tab" data-nf="trial">trial</button>
            <button class="tab" data-nf="active">${t('lic.f_active')}</button>
            <button class="tab" data-nf="expired">${t('lic.f_expired')}</button>
        </div></div>
        <div id="nodewrap"></div>
    </div>
    <div class="card mt"><div class="card-head"><h2>${icon('key')}${t('lic.tiers')}</h2></div>
        <p class="hint">${t('lic.tiers_hint')}</p>
        <div id="tierlist">${tiers.map((tr) => tierRowHtml(tr)).join('')}</div>
        <div class="row mt" style="gap:8px;align-items:center">
            <button type="button" class="btn sm" id="tieradd">${icon('plus')}${t('lic.tier_add')}</button>
            <span class="spacer"></span>
            <button type="button" class="btn primary sm" id="tiersave">${t('common.save')}</button></div>
    </div>
    <div class="card mt"><div class="card-head"><h2>${t('lic.master')}</h2><span class="spacer"></span>
        <button class="btn primary" id="newlic">${icon('plus')}${t('lic.new')}</button></div>
        ${licenses.length ? `<table class="data"><thead><tr><th>${t('lic.key')}</th><th>Tier</th><th>${t('lic.status')}</th><th class="hide-sm">${t('lic.expires')}</th><th class="hide-sm">${t('lic.customer')}</th><th class="num">${t('lic.activations')}</th><th></th></tr></thead><tbody>
            ${licenses.map((l) => `<tr>
                <td class="mono">${esc(l.license_key)}</td><td class="mono">${esc(l.tier)}</td>
                <td>${licBadge(l.status)}</td><td class="hide-sm">${l.expires_at ? fmtDate(l.expires_at) : '∞'}</td>
                <td class="hide-sm">${esc(l.customer || '')}</td><td class="num mono">${l.activations}</td>
                <td class="num">
                    <button class="btn ghost" data-lictoggle="${l.id}" data-status="${esc(l.status)}">${l.status === 'active' ? t('lic.suspend') : t('lic.reactivate')}</button>
                    <button class="btn danger" data-licdel="${l.id}">${t('common.delete')}</button></td></tr>`).join('')}</tbody></table>`
            : `<div class="empty">${t('lic.none')}</div>`}
    </div>` : `<div class="card mt"><div class="empty">${t('lic.need_key')}</div></div>`}`;

    main().querySelector('#licf').addEventListener('submit', async (e) => {
        e.preventDefault();
        try { const r = await api('/license', { method: 'PUT', body: Object.fromEntries(new FormData(e.target)) }); toast(`${t('lic.activated')}: ${r.status || '—'}`, 'ok'); pageLicensing(); }
        catch (err) { toast(t('lic.' + err.message) !== 'lic.' + err.message ? t('lic.' + err.message) : err.message, 'err'); }
    });
    document.getElementById('newlic')?.addEventListener('click', () => newLicenseModal(tiers));

    // Povezani paneli — filter (svi / trial / aktivni / istekli) + brisanje noda
    let nodeFilter = 'all';
    const matchNode = (n) => nodeFilter === 'all' ? true
        : nodeFilter === 'trial' ? n.type === 'trial'
        : nodeFilter === 'active' ? (n.status === 'active' || n.status === 'trial')
        : (n.status === 'expired' || n.status === 'suspended' || n.status === 'revoked');
    // koliko različitih IP-ova/instalacija po istoj licenci (1 licenca = 1 IP)
    const licIps = {};
    nodes.forEach((n) => {
        if (n.type === 'license' && n.license_key) (licIps[n.license_key] ??= new Set()).add(n.ip || '?');
    });
    const renderNodes = () => {
        const wrap = main().querySelector('#nodewrap');
        if (!wrap) return;
        const cnt = main().querySelector('#nodecount');
        if (cnt) cnt.textContent = `${nodes.length} ${t('lic.nodes_count')}`;
        const list = nodes.filter(matchNode);
        wrap.innerHTML = list.length ? `<table class="data"><thead><tr>
            <th>${t('lic.node_fp')}</th><th>${t('lic.node_type')}</th><th>${t('lic.status')}</th>
            <th>IP</th><th class="hide-sm">${t('dist.version')}</th><th class="hide-sm">${t('lic.expires')}</th>
            <th class="num">${t('lic.last_seen')}</th><th></th></tr></thead><tbody>
            ${list.map((n) => {
                const ips = n.type === 'license' && n.license_key ? (licIps[n.license_key]?.size || 1) : 1;
                return `<tr>
                <td class="mono" title="${esc(n.fingerprint)}">${esc(String(n.fingerprint).slice(0, 12))}…</td>
                <td><span class="badge ${n.type === 'license' ? 'ok' : ''}" ${n.license_key ? `title="${esc(n.license_key)}"` : ''}>${n.type === 'license' ? t('lic.type_license') + (n.tier ? ' · ' + esc(n.tier) : '') : t('lic.type_trial')}</span></td>
                <td>${licBadge(n.status)}</td>
                <td class="mono">${esc(n.ip || '—')}${ips > 1 ? ` <span class="badge err" title="${t('lic.multi_ip')}">⚠ ${ips} IP</span>` : ''}</td>
                <td class="mono hide-sm">${esc(n.version || '—')}</td>
                <td class="hide-sm">${n.expires_at ? fmtDate(n.expires_at) : '∞'}</td>
                <td class="num">${timeAgo(n.last_seen)}</td>
                <td class="num"><button class="btn danger sm" data-noderm="${esc(n.fingerprint)}" title="${t('lic.node_remove')}">${icon('x', 13)}</button></td>
            </tr>`;
            }).join('')}</tbody></table>` : `<div class="empty">${t('lic.no_nodes')}</div>`;
        wrap.querySelectorAll('[data-noderm]').forEach((b) => b.addEventListener('click', async () => {
            if (!confirm(t('lic.node_remove_confirm'))) return;
            try {
                await api(`/licenses/nodes/${encodeURIComponent(b.dataset.noderm)}`, { method: 'DELETE' });
                for (let i = nodes.length - 1; i >= 0; i--) if (nodes[i].fingerprint === b.dataset.noderm) nodes.splice(i, 1);
                renderNodes();
                toast(t('lic.node_removed'), 'ok');
            } catch (err) { toast(err.message, 'err'); }
        }));
    };
    main().querySelector('#nodefilter')?.addEventListener('click', (e) => {
        const b = e.target.closest('[data-nf]');
        if (!b) return;
        nodeFilter = b.dataset.nf;
        main().querySelectorAll('#nodefilter .tab').forEach((x) => x.classList.toggle('active', x === b));
        renderNodes();
    });
    renderNodes();

    // Editor tier opcija (master)
    const tierList = document.getElementById('tierlist');
    tierList?.addEventListener('click', (e) => {
        const del = e.target.closest('[data-tierdel]');
        if (del) del.closest('.tier-row')?.remove();
    });
    document.getElementById('tieradd')?.addEventListener('click', () => {
        tierList.insertAdjacentHTML('beforeend', tierRowHtml(''));
        tierList.lastElementChild.querySelector('input')?.focus();
    });
    document.getElementById('tiersave')?.addEventListener('click', async () => {
        const vals = [...tierList.querySelectorAll('input')].map((i) => i.value.trim().toLowerCase()).filter(Boolean);
        if (!vals.length) return toast(t('lic.tiers_empty'), 'err');
        try { await api('/license/tiers', { method: 'PUT', body: { tiers: vals } }); toast(t('lic.tiers_saved'), 'ok'); pageLicensing(); }
        catch (err) { toast(t('lic.' + err.message) !== 'lic.' + err.message ? t('lic.' + err.message) : err.message, 'err'); }
    });
    main().querySelectorAll('[data-lictoggle]').forEach((b) => b.addEventListener('click', async () => {
        try { await api(`/licenses/${b.dataset.lictoggle}`, { method: 'PUT', body: { status: b.dataset.status === 'active' ? 'suspended' : 'active' } }); pageLicensing(); }
        catch (err) { toast(err.message, 'err'); }
    }));
    main().querySelectorAll('[data-licdel]').forEach((b) => b.addEventListener('click', async () => {
        if (!confirm(t('common.confirm_delete'))) return;
        try { await api(`/licenses/${b.dataset.licdel}`, { method: 'DELETE' }); pageLicensing(); }
        catch (err) { toast(err.message, 'err'); }
    }));
}

const tierRowHtml = (v = '') => `<div class="tier-row"><input class="mono" value="${esc(v)}" maxlength="32" placeholder="tier" pattern="[a-z0-9][a-z0-9 _-]{0,31}"><button type="button" class="btn ghost icon" data-tierdel title="${t('common.delete')}">${icon('x')}</button></div>`;

function newLicenseModal(tiers = ['standard', 'pro', 'enterprise']) {
    const modal = openModal(`
        <div class="dialog-head"><h1>${t('lic.new')}</h1><button class="btn ghost icon" data-close>${icon('x')}</button></div>
        <form id="nlf">
            <div class="grid cols-2">
                <div class="field"><label>Tier</label><select name="tier" class="mono">${tiers.map((tr) => `<option>${esc(tr)}</option>`).join('')}</select></div>
                <div class="field"><label>${t('lic.expires')}</label><input name="expires_at" type="date"></div>
            </div>
            <div class="field"><label>${t('lic.customer')}</label><input name="customer" placeholder="Ime / tvrtka"></div>
            <div class="field"><label>${t('dist.notes')}</label><input name="notes"></div>
            <div class="dialog-foot"><button type="button" class="btn" data-close>${t('common.cancel')}</button><button class="btn primary">${t('common.create')}</button></div>
        </form>`);
    modal.querySelector('#nlf').addEventListener('submit', async (e) => {
        e.preventDefault();
        try { const r = await api('/licenses', { method: 'POST', body: Object.fromEntries(new FormData(e.target)) }); modal.close(); toast(`${t('lic.created')}: ${r.license_key}`, 'ok'); pageLicensing(); }
        catch (err) { toast(err.message, 'err'); }
    });
}

async function pageDistribution() {
    setActive('distribution');
    main().innerHTML = `${tabsHtml('server', 'distribution')}<div class="empty">${t('common.loading')}</div>`;
    const [keys, node, releases] = await Promise.all([
        api('/distribution/keys').catch(() => ({})),
        api('/distribution/node').catch(() => ({})),
        api('/distribution/releases').catch(() => []),
    ]);
    main().innerHTML = `${tabsHtml('server', 'distribution')}
    <div class="card"><div class="card-head"><h2>${t('dist.master')}</h2></div>
        <p class="hint" style="margin:0 0 var(--gap)">${t('dist.master_intro')}</p>
        ${keys.has_key ? `
            <div class="field"><label>${t('dist.pubkey')}</label>
                <input class="mono" readonly value="${esc(keys.public_key || '')}" onclick="this.select()">
                <span class="hint">${t('dist.pubkey_hint')}</span></div>` : `
            <button class="btn" id="keygen">${icon('key')}${t('dist.keygen')}</button>`}
        <form id="pubf" class="addform">
            <div class="addform-h">${t('dist.publish')}</div>
            <div class="grid cols-3">
                <div class="field"><label>${t('dist.version')}</label><input name="version" class="mono" placeholder="1.0.1" required></div>
                <div class="field"><label>${t('dist.channel')}</label><select name="channel" class="mono"><option>stable</option><option>beta</option></select></div>
                <div class="field"><label>min_version</label><input name="min_version" class="mono" placeholder="1.0.0"></div>
                <div class="field span-all"><label>${t('dist.url')}</label><input name="package_url" class="mono" placeholder="https://.../forgepanel-1.0.1.tar.gz" required></div>
                <div class="field span-all"><label>SHA-256</label><input name="sha256" class="mono" placeholder="64 hex znakova" required></div>
                <div class="field span-all"><label>${t('dist.notes')}</label><input name="notes" placeholder="Što je novo…"></div>
            </div>
            <div class="addform-foot"><button class="btn primary" ${keys.has_key ? '' : 'disabled'}>${t('dist.publish_btn')}</button></div>
        </form>
        ${releases.length ? `<table class="data mt"><thead><tr><th>${t('dist.version')}</th><th>${t('dist.channel')}</th><th class="hide-sm">SHA-256</th><th class="hide-sm">${t('dist.published')}</th></tr></thead><tbody>
            ${releases.map((r) => `<tr><td class="mono">${esc(r.version)}</td><td><span class="badge ${r.channel === 'beta' ? 'warn' : 'ok'}">${esc(r.channel)}</span></td>
                <td class="mono hide-sm" style="font-size:var(--fs-xs)">${esc(String(r.sha256).slice(0, 16))}…</td><td class="hide-sm">${fmtDate(r.published_at)}</td></tr>`).join('')}</tbody></table>` : ''}
    </div>

    <div class="card mt"><div class="card-head"><h2>${t('dist.node')}</h2></div>
        <p class="hint" style="margin:0 0 var(--gap)">${t('dist.node_intro')}</p>
        <form id="nodef">
            <div class="grid cols-2">
                <div class="field"><label>${t('dist.update_server')}</label><input name="update_server" class="mono" placeholder="https://master.example.com:8443" value="${esc(node.update_server || '')}"></div>
                <div class="field"><label>${t('dist.channel')}</label><select name="update_channel" class="mono"><option ${node.update_channel === 'stable' ? 'selected' : ''}>stable</option><option ${node.update_channel === 'beta' ? 'selected' : ''}>beta</option></select></div>
            </div>
            <div class="field"><label>${t('dist.master_pubkey')}</label><input name="update_pubkey" class="mono" placeholder="base64 javni ključ mastera" value="${esc(node.update_pubkey || '')}"></div>
            <label class="chk"><input type="checkbox" name="update_auto" value="auto" ${node.update_auto === 'auto' ? 'checked' : ''}> ${t('dist.auto')}</label>
            <div style="display:flex;gap:8px;margin-top:10px"><button class="btn primary">${t('common.save')}</button>
                <button type="button" class="btn" id="checkupd">${icon('refresh')}${t('dist.check')}</button></div>
        </form>
        <div id="checkbox" class="mt"></div>
        <div class="hint mt mono">${t('dist.current')}: v${esc(node.current || '1.0.0')}</div>
    </div>`;

    document.getElementById('keygen')?.addEventListener('click', async (e) => {
        e.target.disabled = true;
        try { await api('/distribution/keygen', { method: 'POST', body: {} }); toast(t('dist.key_created'), 'ok'); pageDistribution(); }
        catch (err) { toast(err.message, 'err'); e.target.disabled = false; }
    });
    main().querySelector('#pubf').addEventListener('submit', async (e) => {
        e.preventDefault();
        try { await api('/distribution/releases', { method: 'POST', body: Object.fromEntries(new FormData(e.target)) }); toast(t('dist.published'), 'ok'); pageDistribution(); }
        catch (err) { toast(err.message, 'err'); }
    });
    main().querySelector('#nodef').addEventListener('submit', async (e) => {
        e.preventDefault();
        try { await api('/distribution/node', { method: 'PUT', body: Object.fromEntries(new FormData(e.target)) }); toast(t('system.saved'), 'ok'); }
        catch (err) { toast(err.message, 'err'); }
    });
    document.getElementById('checkupd').addEventListener('click', async () => {
        const box = document.getElementById('checkbox');
        box.innerHTML = `<div class="empty">${t('common.loading')}</div>`;
        try {
            const r = await api('/distribution/check');
            if (!r.configured) { box.innerHTML = `<div class="alert warn">${t('dist.not_configured')}</div>`; return; }
            box.innerHTML = r.update_available
                ? `<div class="alert ok" style="display:flex;align-items:center;gap:10px">
                    <span style="flex:1">${t('dist.available')}: <strong class="mono">v${esc(r.latest)}</strong> (${t('dist.current')} v${esc(r.current)})${r.notes ? ` — ${esc(r.notes)}` : ''}</span>
                    <button class="btn primary sm" id="applyupd">${icon('download')}${t('dist.apply')}</button></div>`
                : `<div class="alert ok">${t('dist.uptodate')} (v${esc(r.current)})</div>`;
            document.getElementById('applyupd')?.addEventListener('click', async (e) => {
                if (!confirm(t('dist.apply_confirm'))) return;
                e.target.disabled = true;
                try { const a = await api('/distribution/apply', { method: 'POST' }); watchTask(a.task_id, `panel update v${a.version}`); toast(t('dist.applying'), 'ok'); }
                catch (err) { toast(err.message, 'err'); e.target.disabled = false; }
            });
        } catch (err) { box.innerHTML = `<div class="alert err">${esc(err.message)}</div>`; }
    });
}

async function pageSystem() {
    setActive('system');
    main().innerHTML = `${tabsHtml('server', 'system')}<div class="card">${t('common.loading')}</div>`;
    let s = {};
    try { s = await api('/settings'); } catch (err) { main().querySelector('.card').innerHTML = `<div class="alert err">${esc(err.message)}</div>`; return; }
    const phpOpts = ['8.5', '8.4', '8.3', '8.2', '8.1'];
    main().innerHTML = `${tabsHtml('server', 'system')}
    <div class="card" style="max-width:680px">
        <div class="card-head"><h2>${t('system.title')}</h2></div>
        <p class="hint" style="margin:0 0 var(--gap)">${t('system.intro')}</p>
        <form id="sysform">
            <div class="field"><label>${t('system.acme_email')}</label>
                <input name="acme_email" type="email" class="mono" value="${esc(s.acme_email ?? '')}" placeholder="admin@example.com">
                <span class="hint">${t('system.acme_email_hint')}</span></div>
            <div class="grid cols-2">
                <div class="field"><label>${t('system.server_ipv4')}</label>
                    <input name="server_ipv4" class="mono" value="${esc(s.server_ipv4 ?? '')}" placeholder="1.2.3.4"></div>
                <div class="field"><label>${t('system.default_php')}</label>
                    <select name="default_php" class="mono">${phpOpts.map((v) => `<option ${v === s.default_php ? 'selected' : ''}>${v}</option>`).join('')}</select></div>
            </div>
            <div class="field"><label>${t('system.panel_fqdn')}</label>
                <input name="panel_fqdn" class="mono" value="${esc(s.panel_fqdn ?? '')}" placeholder="panel.example.com">
                <span class="hint">${t('system.panel_fqdn_hint')}</span></div>
            <button class="btn primary" type="submit">${t('common.save')}</button>
        </form>
    </div>`;
    main().querySelector('#sysform').addEventListener('submit', async (e) => {
        e.preventDefault();
        const settings = Object.fromEntries(new FormData(e.target));
        const btn = e.target.querySelector('button.primary');
        btn.disabled = true;
        try { await api('/settings', { method: 'PUT', body: { settings } }); toast(t('system.saved'), 'ok'); }
        catch (err) { toast(t('settings.' + err.message) !== 'settings.' + err.message ? t('settings.' + err.message) : err.message, 'err'); }
        finally { btn.disabled = false; }
    });
}

// ---------------------------------------------------------------- migrator (admin)
async function pageMigrator() {
    setActive('migrator');
    main().innerHTML = `${tabsHtml('server', 'migrator')}
    <div class="card" style="max-width:760px">
        <div class="card-head"><h2>${t('migrator.title')}</h2></div>
        <p class="hint">${t('migrator.intro')}</p>
        <form id="upf">
            <div class="grid cols-2">
                <div class="field"><label>${t('migrator.source')}</label>
                    <select name="type" class="mono"><option value="cpanel">cPanel (cpmove)</option><option value="plesk">Plesk (backup XML)</option></select></div>
                <div class="field"><label>${t('migrator.archive')}</label>
                    <input type="file" name="archive" accept=".tar,.gz,.tgz,.zip" required></div>
            </div>
            <button class="btn primary">${icon('upload')}${t('migrator.upload')}</button>
        </form>
        <div id="migout" class="mt"></div>
    </div>`;

    main().querySelector('#upf').addEventListener('submit', async (e) => {
        e.preventDefault();
        const out = document.getElementById('migout');
        const fd = new FormData(e.target);
        out.innerHTML = `<div class="empty">${t('migrator.uploading')}</div>`;
        try {
            const up = await apiUpload('/migrator/upload', fd);
            out.innerHTML = `<div class="empty">${t('migrator.analyzing')}</div>`;
            const parsed = await api(`/migrator/${up.token}/analyze`);
            renderMigratorReport(out, up.token, up.type, parsed);
        } catch (err) { out.innerHTML = `<div class="alert err">${esc(err.message)}</div>`; }
    });
}

async function renderMigratorReport(out, token, type, parsed) {
    const subs = await api('/subscriptions').catch(() => []);
    const list = (arr, max = 20) => (arr || []).slice(0, max).map((x) => `<span class="badge">${esc(x)}</span>`).join(' ') || '—';
    out.innerHTML = `
        <div class="alert ok">${t('migrator.found')} — ${type}</div>
        <div class="mig-block"><b>${t('nav.websites')} (${(parsed.domains || []).length})</b><div class="mt">${list(parsed.domains)}</div></div>
        <div class="mig-block"><b>${t('nav.databases')} (${(parsed.databases || []).length})</b><div class="mt">${list(parsed.databases)}</div></div>
        <div class="mig-block"><b>${t('dash.mailboxes')} (${(parsed.email_accounts || []).length})</b><div class="mt">${list(parsed.email_accounts)}</div></div>
        <form id="impf" class="mt">
            <div class="field" style="max-width:360px"><label>${t('migrator.target_sub')}</label>
                <select name="subscription_id" class="mono" required>
                    ${subs.map((s) => `<option value="${s.id}">#${s.id} — ${esc(s.email || '')} (${esc(s.plan || '')})</option>`).join('')}
                </select></div>
            <button class="btn primary">${icon('download')}${t('migrator.import')}</button>
            <span class="hint">${t('migrator.import_hint')}</span>
        </form>
        <div id="impout" class="mt"></div>`;

    out.querySelector('#impf').addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!confirm(t('migrator.confirm_import'))) return;
        const subscription_id = Number(new FormData(e.target).get('subscription_id'));
        const impout = document.getElementById('impout');
        impout.innerHTML = `<div class="empty">${t('common.loading')}</div>`;
        try {
            const r = await api(`/migrator/${token}/import`, { method: 'POST', body: { subscription_id } });
            impout.innerHTML = `<div class="alert ok">
                ${t('migrator.imported')}: ${r.vhosts.length} ${t('nav.websites')}, ${r.databases.length} ${t('nav.databases')}.
                ${r.skipped.length ? `<div class="mt small">${t('migrator.skipped')}: ${r.skipped.map(esc).join(', ')}</div>` : ''}</div>`;
        } catch (err) { impout.innerHTML = `<div class="alert err">${esc(err.message)}</div>`; }
    });
}

// multipart upload (api() je JSON-only)
async function apiUpload(path, formData) {
    const res = await fetch(`/api/v1${path}`, {
        method: 'POST',
        headers: { ...(state.token ? { Authorization: `Bearer ${state.token}` } : {}) },
        body: formData,
    });
    const json = await res.json().catch(() => ({ ok: false, error: 'bad_response' }));
    if (!json.ok) throw new Error(json.error ?? `http_${res.status}`);
    return json.data;
}

async function pageAbout() {
    setActive('about', [t('nav.about')]);
    const b = brandName();
    main().innerHTML = `
    <div class="about-hero card">
        <div class="about-mark">${state.branding?.logo_url ? `<img src="${esc(state.branding.logo_url)}" alt="">` : icon('zap', 30)}</div>
        <div>
            <h1 style="font-size:20px">${esc(b)}</h1>
            <div class="about-sub mono">v${PANEL_VERSION} · Ubuntu Server 26.04 LTS</div>
            <p class="about-tag">${t('about.tagline')}</p>
        </div>
    </div>
    <div class="grid cols-2 mt">
        <div class="card"><div class="about-card-h">${icon('gear')}<h2>${t('about.tech')}</h2></div>
            <ul class="about-list">
                <li>PHP 8.1–8.5 paralelno (+ <b>Legacy PHP 7.x preko Dockera</b>), <span class="mono">strict_types</span>, bez frameworka</li>
                <li>MariaDB / MySQL (<span class="mono">utf8mb4</span>, prepared statements)</li>
                <li>Vanilla JS (ES2024) + Web Components, bez build alata</li>
                <li>SSE realtime (log / task / monitoring stream)</li>
                <li>Ubuntu 26.04 native: apt deb822, systemd, ufw, cgroup v2</li>
            </ul></div>
        <div class="card"><div class="about-card-h">${icon('grid')}<h2>${t('about.modules')}</h2></div>
            <ul class="about-list">
                <li>Web stranice: nginx / nginx→Apache / Legacy PHP, AutoSSL, HTTP/3</li>
                <li>Git deploy + universal staging, file manager s editorom</li>
                <li>Baze + phpMyAdmin, Mail (Postfix/Dovecot/Rspamd) + deliverability</li>
                <li>DNS (BIND) + Cloudflare, Docker + app marketplace (WP, Nextcloud, Ghost, Node, Python)</li>
                <li>Backup, monitoring, malware/WAF, firewall, reseller + delegirani pristup</li>
            </ul></div>
        <div class="card"><div class="about-card-h">${icon('shield')}<h2>${t('about.security')}</h2></div>
            <ul class="about-list">
                <li>Lozinke argon2id, TOTP 2FA, rate-limiting</li>
                <li>CSRF tokeni, strogi CSP + puni security headeri</li>
                <li>Agent op-whitelist — nikad raw shell komande</li>
                <li>Izolacija vhosta: vlastiti user + FPM pool + open_basedir</li>
                <li>fail2ban, AppArmor, malware skener i WAF ugrađeni (bez doplate)</li>
            </ul></div>
        <div class="card"><div class="about-card-h">${icon('server')}<h2>${t('about.arch')}</h2></div>
            <ul class="about-list">
                <li>3 sloja: web (bez roota) → agent socket → sustav</li>
                <li>Agent (forge-agentd) kao root, systemd hardening</li>
                <li>Task queue za duge operacije + append-only audit log</li>
                <li>Izolirani panel stack (vlastiti nginx + PHP-FPM pool)</li>
                <li>API-first: sve dostupno na <span class="mono">/api/v1</span></li>
            </ul></div>
    </div>
    <div class="card mt about-rights">
        <div class="about-card-h">${icon('lock')}<h2>${t('about.rights')}</h2></div>
        <p>${t('about.rights_body')}</p>
        <p class="mono about-copy">© ${new Date().getFullYear()} ${esc(b)} · HostForge.net — Žarko Strelec. ${t('about.rights_reserved')}</p>
    </div>
    <div class="grid cols-3 mt">
        <a class="card about-link" href="https://hostforge.net" target="_blank" rel="noopener">${icon('globe')}<div><strong>HostForge.net</strong><span>${t('about.dev_site')}</span></div></a>
        <a class="card about-link" href="https://github.com/zarkostrelec" target="_blank" rel="noopener">${icon('box')}<div><strong>github.com/zarkostrelec</strong><span>${t('about.source')}</span></div></a>
        <a class="card about-link" href="mailto:office@hostforge.net">${icon('mail')}<div><strong>office@hostforge.net</strong><span>${t('about.support')}</span></div></a>
    </div>
    <div class="about-foot mono">${esc(b)} v${PANEL_VERSION} · © ${new Date().getFullYear()} HostForge.net</div>`;
}

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
    <div class="card mt"><h2>fail2ban</h2><div id="f2b">${t('common.loading')}</div></div>
    <div class="card mt"><div class="card-head"><h2>${t('waf.title')}</h2>
        <select id="wafvh" class="mono"></select></div>
        <p class="hint">${t('waf.hint')}</p>
        <div id="wafbox">${t('common.loading')}</div></div>`;

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

    // ModSecurity WAF — vhost selektor + stanje/log/whitelist
    const vhosts = await api('/vhosts').catch(() => []);
    const sel = document.getElementById('wafvh');
    if (!vhosts.length) {
        document.getElementById('wafbox').innerHTML = `<div class="empty">${t('waf.no_vhosts')}</div>`;
    } else {
        sel.innerHTML = vhosts.map((v) => `<option value="${v.id}">${esc(v.domain)}</option>`).join('');
        sel.addEventListener('change', () => loadWaf(Number(sel.value)));
        loadWaf(Number(vhosts[0].id));
    }
}

async function loadWaf(vhostId) {
    const box = document.getElementById('wafbox');
    if (!box) return;
    box.innerHTML = `<div class="empty">${t('common.loading')}</div>`;
    const [state_, log] = await Promise.all([
        api(`/vhosts/${vhostId}/waf`).catch(() => ({ enabled: false, paranoia: 1, whitelist: [] })),
        api(`/vhosts/${vhostId}/waf/log`).catch(() => ({ events: [] })),
    ]);
    const events = log.events || [];
    box.innerHTML = `
        <div class="row" style="gap:12px;align-items:center;flex-wrap:wrap">
            <label class="inline" style="gap:8px"><input type="checkbox" id="wafon" ${state_.enabled ? 'checked' : ''}> ${t('waf.engine')}</label>
            <label class="inline mono" style="gap:6px">${t('waf.paranoia')}
                <select id="wafpar" class="mono">${[1, 2, 3, 4].map((p) => `<option ${p === state_.paranoia ? 'selected' : ''}>${p}</option>`).join('')}</select></label>
            <button class="btn sm primary" id="wafapply">${t('common.save')}</button>
        </div>
        ${state_.whitelist.length ? `<div class="mt"><b class="small">${t('waf.whitelisted')}:</b>
            ${state_.whitelist.map((id) => `<span class="badge ok mono">${esc(id)} <button class="linkx" data-unwl="${esc(id)}">✕</button></span>`).join(' ')}</div>` : ''}
        <h2 class="mt">${t('waf.blocked')}</h2>
        ${events.length ? `<table class="data"><thead><tr>
            <th>${t('waf.rule')}</th><th>${t('waf.message')}</th><th class="hide-sm">URI</th><th class="num">${t('deliver.count')}</th><th></th>
        </tr></thead><tbody>
        ${events.map((e) => `<tr>
            <td class="mono">${esc(e.rule_id)}</td>
            <td class="small">${esc((e.msg || '').slice(0, 70))}</td>
            <td class="mono small hide-sm">${esc((e.uri || '').slice(0, 40))}</td>
            <td class="num mono">${e.count}</td>
            <td class="num">${state_.whitelist.includes(e.rule_id) ? `<span class="badge ok">${t('waf.allowed')}</span>` : `<button class="btn sm" data-wl="${esc(e.rule_id)}">${t('waf.whitelist')}</button>`}</td>
        </tr>`).join('')}</tbody></table>` : `<div class="empty">${t('waf.no_blocks')}</div>`}`;

    document.getElementById('wafapply').addEventListener('click', async () => {
        try {
            const r = await api(`/vhosts/${vhostId}/waf`, { method: 'POST', body: {
                enabled: document.getElementById('wafon').checked,
                paranoia: Number(document.getElementById('wafpar').value),
            } });
            watchTask(r.task_id, 'waf.toggle');
            toast(t('waf.applied'));
        } catch (err) { toast(err.message, 'err'); }
    });
    box.querySelectorAll('[data-wl]').forEach((b) => b.addEventListener('click', async () => {
        try { await api(`/vhosts/${vhostId}/waf/whitelist`, { method: 'POST', body: { rule_id: b.dataset.wl } }); loadWaf(vhostId); toast(t('waf.whitelisted_ok')); }
        catch (err) { toast(err.message, 'err'); }
    }));
    box.querySelectorAll('[data-unwl]').forEach((b) => b.addEventListener('click', async () => {
        try { await api(`/vhosts/${vhostId}/waf/whitelist/${b.dataset.unwl}`, { method: 'DELETE' }); loadWaf(vhostId); }
        catch (err) { toast(err.message, 'err'); }
    }));
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
            watchTask(r.task_id, `ažuriranje ${b.dataset.apply}`);
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
            ? `<span class="badge ok">${t('profile.enabled')}</span>
               <div class="recovery-meta">
                   <span class="muted">${t('profile.recovery_remaining')}: <b class="mono">${state.me.recovery_codes_remaining ?? 0}</b></span>
                   <button class="btn sm" id="recoveryregen">${t('profile.recovery_regenerate')}</button>
               </div>`
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
                    const r = await api('/auth/twofa/confirm', { method: 'POST', body: { code: new FormData(e.target).get('code') } });
                    toast(t('profile.totp_enabled'));
                    box.innerHTML = `
                        <span class="badge ok">${t('profile.enabled')}</span>
                        <h3 style="margin:14px 0 4px">${t('profile.recovery_title')}</h3>
                        <p>${t('profile.recovery_intro')}</p>
                        ${recoveryCodesHtml(r.recovery_codes || [])}`;
                    bindRecoveryCopy(box, r.recovery_codes || []);
                } catch (err) { toast(err.message, 'err'); }
            });
        } catch (err) { toast(err.message, 'err'); }
    });

    document.getElementById('recoveryregen')?.addEventListener('click', async () => {
        if (!confirm(t('profile.recovery_regenerate_confirm'))) return;
        try {
            const r = await api('/auth/twofa/recovery-codes', { method: 'POST' });
            const box = document.getElementById('totpbox');
            box.innerHTML = `
                <span class="badge ok">${t('profile.enabled')}</span>
                <h3 style="margin:14px 0 4px">${t('profile.recovery_title')}</h3>
                <p>${t('profile.recovery_intro')}</p>
                ${recoveryCodesHtml(r.recovery_codes || [])}`;
            bindRecoveryCopy(box, r.recovery_codes || []);
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
    [/^#\/deliverability$/, pageDeliverability],
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
    [/^#\/system$/, pageSystem],
    [/^#\/migrator$/, pageMigrator],
    [/^#\/distribution$/, pageDistribution],
    [/^#\/licensing$/, pageLicensing],
    [/^#\/about$/, pageAbout],
    [/^#\/assistant$/, pageAssistant],
    [/^#\/users$/, pageUsers],
];

async function route() {
    if (!state.me) return;
    // počisti monitoring auto-refresh pri svakoj navigaciji (izbjegni curenje)
    if (state.monTimer) { clearInterval(state.monTimer); state.monTimer = null; }
    state.pageRefresh = null;
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
    licenseBanner();
}

// Banner kad je licenca ovog panela konfigurirana ali NIJE aktivna (admin)
async function licenseBanner() {
    if (state.me.role !== 'admin') return;
    try {
        const l = await api('/license');
        if (!l.configured || l.status === 'active' || l.status === '') return;
        const bar = document.createElement('div');
        bar.className = 'license-bar';
        bar.innerHTML = `${icon('lock')}<span>${t('lic.banner_' + l.status) !== 'lic.banner_' + l.status ? t('lic.banner_' + l.status) : t('lic.banner_inactive')}</span>
            <a href="#/licensing">${t('nav.licensing')}</a>`;
        document.querySelector('.main')?.prepend(bar);
    } catch { /* tiho */ }
}

document.documentElement.dataset.theme = state.theme;
await loadLang();
await loadBranding();
if (state.token) {
    try { await enter(); } catch { logoutLocal(); }
} else {
    renderLogin();
}
