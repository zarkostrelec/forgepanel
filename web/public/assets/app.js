/* ForgePanel SPA — vanilla ES2024, bez build alata. UI je samo potrošač REST API-ja. */

// ---------------------------------------------------------------- state
const state = {
    token: localStorage.getItem('fp_token'),
    me: null,
    lang: {},
    langCode: localStorage.getItem('fp_lang') ?? 'hr',
    theme: localStorage.getItem('fp_theme') ?? 'dark',
    activeTasks: new Map(),
};

const $app = document.getElementById('app');

// ---------------------------------------------------------------- i18n + formati (europski)
async function loadLang() {
    const res = await fetch(`/lang/${state.langCode}.json`);
    state.lang = res.ok ? await res.json() : {};
}
const t = (key) => state.lang[key] ?? key;

const fmtDate = (s) => {
    if (!s) return '—';
    const d = new Date(String(s).replace(' ', 'T'));
    return new Intl.DateTimeFormat('hr-HR', { dateStyle: 'short', timeStyle: 'short' }).format(d);
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

// ---------------------------------------------------------------- ikone (Lucide-style outline)
const ICONS = {
    home: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>',
    globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
    db: '<ellipse cx="12" cy="5.5" rx="8" ry="2.8"/><path d="M4 5.5v13c0 1.5 3.6 2.8 8 2.8s8-1.3 8-2.8v-13"/><path d="M4 12c0 1.5 3.6 2.8 8 2.8s8-1.3 8-2.8"/>',
    lock: '<rect x="4.5" y="10.5" width="15" height="10" rx="2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>',
    tasks: '<path d="M4 6h12M4 12h16M4 18h9"/>',
    chart: '<path d="M4 20V4"/><path d="M4 20h16"/><path d="M8 16v-5M13 16V8M18 16v-8"/>',
    folder: '<path d="M3 6.5A1.5 1.5 0 0 1 4.5 5h4l2 2.5h9A1.5 1.5 0 0 1 21 9v9.5a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18.5z"/>',
    file: '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/>',
    menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
    x: '<path d="M6 6l12 12M18 6 6 18"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.9 4.9l1.8 1.8M17.3 17.3l1.8 1.8M19.1 4.9l-1.8 1.8M6.7 17.3l-1.8 1.8"/>',
    activity: '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
    user: '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c1.2-3.5 3.8-5 7-5s5.8 1.5 7 5"/>',
    refresh: '<path d="M20 12a8 8 0 1 1-2.3-5.6"/><path d="M20 4v4.5h-4.5"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
};
const icon = (name) => `<svg viewBox="0 0 24 24" aria-hidden="true">${ICONS[name] ?? ''}</svg>`;

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
        this.innerHTML = `<div class="dialog">
            <input class="palette-input" placeholder="Traži domene, akcije, postavke…" autocomplete="off">
            <div class="palette-list"></div>
        </div>`;
        document.body.append(this);

        this.actions = [
            { label: t('nav.dashboard'), type: 'stranica', go: '#/dashboard' },
            { label: t('nav.websites'), type: 'stranica', go: '#/websites' },
            { label: t('nav.databases'), type: 'stranica', go: '#/databases' },
            { label: t('nav.ssl'), type: 'stranica', go: '#/ssl' },
            { label: t('nav.tasks'), type: 'stranica', go: '#/tasks' },
            { label: t('nav.monitoring'), type: 'stranica', go: '#/monitoring' },
            { label: t('vhost.create'), type: 'akcija', run: () => createVhostModal() },
            { label: t('db.create'), type: 'akcija', run: () => createDbModal() },
            { label: 'Tamna/svijetla tema', type: 'akcija', run: toggleTheme },
            { label: t('auth.logout'), type: 'akcija', run: doLogout },
        ];
        try {
            (await api('/vhosts')).forEach((v) =>
                this.actions.push({ label: v.domain, type: 'domena', go: `#/websites/${v.id}` }));
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
        return this.actions.filter((a) => a.label.toLowerCase().includes(q)).slice(0, 12);
    }
    renderList() {
        this.list.innerHTML = this.filtered().map((a, i) =>
            `<div class="palette-item${i === this.idx ? ' active' : ''}" data-i="${i}">
                <span>${esc(a.label)}</span><span class="type">${a.type}</span>
            </div>`).join('') || '<div class="empty">Nema rezultata</div>';
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

document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k' && state.me) {
        e.preventDefault();
        palette.open();
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
function renderLogin(step = 'login', preToken = null) {
    $app.innerHTML = `
    <div class="login-wrap"><div class="card login-card">
        <div class="brand"><div class="brand-mark">F</div><div class="brand-name">ForgePanel</div></div>
        <div class="alert err" hidden></div>
        ${step === 'login' ? `
            <form id="f">
                <div class="field"><label>${t('auth.email')}</label><input name="email" type="email" required autocomplete="username"></div>
                <div class="field"><label>${t('auth.password')}</label><input name="password" type="password" required autocomplete="current-password"></div>
                <button class="btn primary" style="width:100%">${t('auth.login')}</button>
            </form>` : `
            <form id="f">
                <div class="field"><label>${t('auth.twofa_code')}</label><input name="code" inputmode="numeric" pattern="\\d{6}" maxlength="6" required autofocus class="mono"></div>
                <button class="btn primary" style="width:100%">${t('auth.login')}</button>
            </form>`}
    </div></div>`;

    const form = document.getElementById('f');
    const alertEl = $app.querySelector('.alert');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const data = Object.fromEntries(new FormData(form));
        try {
            if (step === 'login') {
                const r = await api('/auth/login', { method: 'POST', body: data });
                state.token = r.token;
                if (r.status === 'twofa_required') return renderLogin('twofa', r.token);
                localStorage.setItem('fp_token', r.token);
                await enter();
            } else {
                state.token = preToken;
                await api('/auth/twofa', { method: 'POST', body: { code: data.code } });
                localStorage.setItem('fp_token', preToken);
                await enter();
            }
        } catch (err) {
            alertEl.hidden = false;
            alertEl.textContent = t('auth.' + err.message) !== 'auth.' + err.message ? t('auth.' + err.message) : err.message;
        }
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

const NAV = [
    ['dashboard', 'home', 'nav.dashboard'],
    ['websites', 'globe', 'nav.websites'],
    ['databases', 'db', 'nav.databases'],
    ['ssl', 'lock', 'nav.ssl'],
    ['tasks', 'tasks', 'nav.tasks'],
    ['monitoring', 'chart', 'nav.monitoring'],
];

function renderShell() {
    $app.innerHTML = `
    <div class="shell">
        <aside class="sidebar">
            <div class="brand"><div class="brand-mark">F</div><div class="brand-name">ForgePanel</div></div>
            <nav class="nav">
                ${NAV.map(([page, ic, key]) =>
                    `<a href="#/${page}" data-page="${page}">${icon(ic)}<span class="nav-label">${t(key)}</span></a>`).join('')}
            </nav>
            <div class="sidebar-foot mono">${esc(state.me.email)}<br>${esc(state.me.role)}</div>
        </aside>
        <div class="main">
            <header class="topbar">
                <button class="btn ghost icon menu-btn" aria-label="izbornik">${icon('menu')}</button>
                <div class="search-hint"><span>Traži… </span><span class="kbd">Ctrl K</span></div>
                <div class="spacer"></div>
                <button class="btn ghost icon tray-btn" aria-label="zadaci">${icon('activity')}<span class="dot"></span></button>
                <button class="btn ghost icon theme-btn" aria-label="tema">${icon('sun')}</button>
                <button class="btn ghost icon logout-btn" aria-label="${t('auth.logout')}">${icon('user')}</button>
            </header>
            <main class="content"></main>
        </div>
    </div>`;

    $app.querySelector('.search-hint').addEventListener('click', () => palette.open());
    $app.querySelector('.theme-btn').addEventListener('click', toggleTheme);
    $app.querySelector('.logout-btn').addEventListener('click', doLogout);
    $app.querySelector('.menu-btn').addEventListener('click', () =>
        $app.querySelector('.shell').classList.toggle('nav-open'));
    $app.querySelector('.tray-btn').addEventListener('click', (e) => {
        e.stopPropagation();
        const existing = document.querySelector('.tray-pop');
        if (existing) return existing.remove();
        const pop = document.createElement('div');
        pop.className = 'tray-pop';
        pop.innerHTML = trayHtml();
        $app.querySelector('.main').append(pop);
        document.addEventListener('click', () => pop.remove(), { once: true });
    });
    renderTray();
}

const main = () => $app.querySelector('.content');

function setActive(page) {
    $app.querySelectorAll('.nav a').forEach((a) => a.classList.toggle('active', a.dataset.page === page));
    $app.querySelector('.shell')?.classList.remove('nav-open');
}

// ---------------------------------------------------------------- stranice
async function pageDashboard() {
    setActive('dashboard');
    main().innerHTML = `<div class="page-head"><h1>${t('nav.dashboard')}</h1></div><div class="empty">${t('common.loading')}</div>`;

    const isAdmin = state.me.role === 'admin';
    const [vhosts, certs, metrics, services] = await Promise.all([
        api('/vhosts').catch(() => []),
        api('/ssl').catch(() => []),
        isAdmin ? api('/monitoring/now').catch(() => null) : null,
        isAdmin ? api('/monitoring/services').catch(() => null) : null,
    ]);

    const expiring = certs.filter((c) => new Date(String(c.expires_at).replace(' ', 'T')) - Date.now() < 30 * 864e5);

    main().innerHTML = `
    <div class="page-head"><h1>${t('nav.dashboard')}</h1></div>
    <div class="grid cols-4">
        <div class="card stat"><div class="label">${t('nav.websites')}</div><div class="value">${vhosts.length}</div></div>
        <div class="card stat"><div class="label">SSL &lt; 30 dana</div><div class="value">${expiring.length}</div></div>
        ${metrics ? `
        <div class="card stat"><div class="label">Load (1m) / CPU</div>
            <div class="value">${metrics.load[0].toFixed(2)}<span class="sub"> / ${metrics.cpu_count}</span></div></div>
        <div class="card stat"><div class="label">RAM slobodno</div>
            <div class="value">${fmtBytes(metrics.mem_available_bytes)}</div>
            <div class="sub">od ${fmtBytes(metrics.mem_total_bytes)} · disk ${fmtBytes(metrics.disk_free_bytes)} slobodno</div></div>` : ''}
    </div>
    ${services ? `
    <div class="card mt"><h2>Servisi</h2><table class="data"><tbody>
        ${Object.entries(services).map(([name, props]) => `
            <tr><td class="mono">${esc(name)}</td>
            <td><span class="badge ${props.ActiveState === 'active' ? 'ok' : 'err'}">${esc(props.ActiveState ?? '?')}</span></td>
            <td class="mono hide-sm">${props.MemoryCurrent && props.MemoryCurrent !== '[not set]' ? fmtBytes(props.MemoryCurrent) : ''}</td></tr>`).join('')}
    </tbody></table></div>` : ''}
    <div class="card mt"><h2>${t('nav.websites')}</h2>${vhostTable(vhosts.slice(0, 8))}</div>`;

    bindVhostRows();
}

const statusBadge = (s) => {
    const kind = { active: 'ok', done: 'ok', creating: 'info', running: 'info', pending: 'info', suspended: 'warn' }[s] ?? 'err';
    return `<span class="badge ${kind}">${t('vhost.status.' + s) !== 'vhost.status.' + s ? t('vhost.status.' + s) : esc(s)}</span>`;
};

const vhostTable = (vhosts) => vhosts.length ? `
    <table class="data"><thead><tr>
        <th>${t('vhost.domain')}</th><th>PHP</th><th class="hide-sm">Backend</th><th>Status</th><th class="hide-sm">Kreirano</th>
    </tr></thead><tbody>
    ${vhosts.map((v) => `
        <tr class="row-link" data-vhost="${v.id}">
            <td class="mono">${esc(v.domain)}</td>
            <td class="mono">${esc(v.php_version)}</td>
            <td class="mono hide-sm">${esc(v.web_backend)}</td>
            <td>${statusBadge(v.status)}</td>
            <td class="hide-sm">${fmtDate(v.created_at)}</td>
        </tr>`).join('')}
    </tbody></table>` : `<div class="empty">${t('nav.websites')}: 0</div>`;

const bindVhostRows = () => main().querySelectorAll('[data-vhost]').forEach((tr) =>
    tr.addEventListener('click', () => { location.hash = `#/websites/${tr.dataset.vhost}`; }));

async function pageWebsites() {
    setActive('websites');
    main().innerHTML = `
    <div class="page-head"><h1>${t('nav.websites')}</h1><div class="spacer"></div>
        <button class="btn primary" id="new">${icon('plus')}${t('vhost.create')}</button></div>
    <div class="card">${t('common.loading')}</div>`;
    document.getElementById('new').addEventListener('click', createVhostModal);

    const vhosts = await api('/vhosts');
    main().querySelector('.card').innerHTML = vhostTable(vhosts);
    bindVhostRows();
}

function createVhostModal() {
    const modal = openModal(`
        <div class="dialog-head"><h1>${t('vhost.create')}</h1><button class="btn ghost icon" data-close>${icon('x')}</button></div>
        <form id="vf">
            <div class="field"><label>${t('vhost.domain')}</label>
                <input name="domain" required placeholder="example.com" class="mono" autocomplete="off">
                <span class="hint">Bez www — alias se dodaje automatski (AutoSSL pokriva oba).</span></div>
            <div class="field"><label>${t('vhost.php_version')}</label>
                <select name="php_version">${['8.4', '8.3', '8.2', '8.1'].map((v) => `<option>${v}</option>`).join('')}</select></div>
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

    main().innerHTML = `
    <div class="page-head">
        <h1 class="mono">${esc(vhost.domain)}</h1>${statusBadge(vhost.status)}
        <div class="spacer"></div>
        <button class="btn" id="renew">${icon('refresh')}SSL renew</button>
        <button class="btn danger" id="del">${t('common.delete')}</button>
    </div>
    <div class="grid cols-2">
        <div class="card">
            <h2>Postavke</h2>
            <table class="data"><tbody>
                <tr><td>${t('vhost.php_version')}</td><td>
                    <select id="php" class="mono">${['8.1', '8.2', '8.3', '8.4'].map((v) =>
                        `<option ${v === vhost.php_version ? 'selected' : ''}>${v}</option>`).join('')}</select></td></tr>
                <tr><td>Backend</td><td class="mono">${esc(vhost.web_backend)}</td></tr>
                <tr><td>Sistemski user</td><td class="mono">${esc(vhost.sys_user)}</td></tr>
                <tr><td>Docroot</td><td class="mono">${esc(vhost.docroot)}</td></tr>
                <tr><td>Kreirano</td><td>${fmtDate(vhost.created_at)}</td></tr>
            </tbody></table>
        </div>
        <div class="card">
            <div class="page-head"><h2>${t('nav.files')}</h2></div>
            <div id="fm"></div>
        </div>
    </div>`;

    main().querySelector('#php').addEventListener('change', async (e) => {
        try {
            await api(`/vhosts/${id}/php`, { method: 'PUT', body: { php_version: e.target.value } });
            toast(`PHP → ${e.target.value}`);
        } catch (err) { toast(err.message, 'err'); route(); }
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

    fileManager(vhost, main().querySelector('#fm'), '/httpdocs');
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
    <div class="page-head">
        <div class="crumbs">
            <a href="#" data-go="/">${esc(vhost.domain)}</a>
            ${parts.map((p, i) => `<span class="sep">/</span><a href="#" data-go="/${parts.slice(0, i + 1).join('/')}">${esc(p)}</a>`).join('')}
        </div>
        <div class="spacer"></div>
        <button class="btn icon" data-mkdir title="novi direktorij">${icon('folder')}</button>
    </div>
    <table class="data"><tbody>
        ${entries.map((en, i) => `
        <tr class="row-link" data-i="${i}">
            <td class="cell-icon">${icon(en.type === 'dir' ? 'folder' : 'file')}</td>
            <td class="mono">${esc(en.name)}</td>
            <td class="mono num hide-sm">${en.type === 'file' ? fmtBytes(en.size_bytes) : ''}</td>
            <td class="mono hide-sm">${esc(en.mode)}</td>
            <td class="hide-sm">${fmtDate(en.mtime)}</td>
        </tr>`).join('') || `<tr><td><div class="empty">prazno</div></td></tr>`}
    </tbody></table>`;

    container.querySelectorAll('[data-go]').forEach((a) => a.addEventListener('click', (e) => {
        e.preventDefault();
        fileManager(vhost, container, a.dataset.go === '/' ? '' : a.dataset.go);
    }));
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
        <div class="field"><textarea class="mono" spellcheck="false">${esc(content)}</textarea></div>
        <div class="dialog-foot">
            <button class="btn danger" id="fdel">${t('common.delete')}</button>
            <span class="spacer" style="flex:1"></span>
            <button class="btn" data-close>${t('common.cancel')}</button>
            <button class="btn primary" id="fsave">${t('common.save')}</button>
        </div>`, { wide: true });

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

// ---------------------------------------------------------------- databases / ssl / tasks / monitoring
async function pageDatabases() {
    setActive('databases');
    main().innerHTML = `
    <div class="page-head"><h1>${t('nav.databases')}</h1><div class="spacer"></div>
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
            <td class="num"><button class="btn ghost" data-user="${d.id}">+ ${t('db.user')}</button>
                <button class="btn danger" data-del="${d.id}" data-name="${esc(d.name)}">${t('common.delete')}</button></td>
        </tr>`).join('')}</tbody></table>` : `<div class="empty">${t('nav.databases')}: 0</div>`;

    main().querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', async () => {
        if (!confirm(`${t('common.confirm_delete')} (${b.dataset.name})`)) return;
        try { await api(`/databases/${b.dataset.del}`, { method: 'DELETE' }); pageDatabases(); }
        catch (err) { toast(err.message, 'err'); }
    }));
    main().querySelectorAll('[data-user]').forEach((b) => b.addEventListener('click', () => createDbUserModal(b.dataset.user)));
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
    main().innerHTML = `<div class="page-head"><h1>${t('nav.ssl')}</h1></div><div class="card">${t('common.loading')}</div>`;
    const certs = await api('/ssl');
    main().querySelector('.card').innerHTML = certs.length ? `
        <table class="data"><thead><tr><th>Hostname</th><th class="hide-sm">Tip</th><th>${t('ssl.expires')}</th><th>Status</th></tr></thead><tbody>
        ${certs.map((c) => {
            const days = Math.floor((new Date(String(c.expires_at).replace(' ', 'T')) - Date.now()) / 864e5);
            return `<tr>
                <td class="mono">${esc(c.hostname)}</td>
                <td class="mono hide-sm">${esc(c.type)}</td>
                <td>${fmtDate(c.expires_at)} <span class="badge ${days < 14 ? 'err' : days < 30 ? 'warn' : 'ok'}">${days} d</span></td>
                <td>${statusBadge(c.status)}</td></tr>`;
        }).join('')}</tbody></table>` : `<div class="empty">${t('nav.ssl')}: 0</div>`;
}

async function pageTasks() {
    setActive('tasks');
    main().innerHTML = `<div class="page-head"><h1>${t('nav.tasks')}</h1></div><div class="card">${t('common.loading')}</div>`;
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

async function pageMonitoring() {
    setActive('monitoring');
    main().innerHTML = `<div class="page-head"><h1>${t('nav.monitoring')}</h1></div><div class="empty">${t('common.loading')}</div>`;
    try {
        const m = await api('/monitoring/now');
        main().innerHTML = `
        <div class="page-head"><h1>${t('nav.monitoring')}</h1></div>
        <div class="grid cols-4">
            <div class="card stat"><div class="label">Load 1/5/15</div>
                <div class="value">${m.load.map((l) => l.toFixed(2)).join(' ')}</div><div class="sub">${m.cpu_count} jezgri</div></div>
            <div class="card stat"><div class="label">RAM</div>
                <div class="value">${fmtBytes(m.mem_total_bytes - m.mem_available_bytes)}</div>
                <div class="sub">od ${fmtBytes(m.mem_total_bytes)}</div></div>
            <div class="card stat"><div class="label">Disk</div>
                <div class="value">${fmtBytes(m.disk_total_bytes - m.disk_free_bytes)}</div>
                <div class="sub">od ${fmtBytes(m.disk_total_bytes)}</div></div>
            <div class="card stat"><div class="label">Uptime</div>
                <div class="value">${Math.floor(m.uptime_s / 86400)}d ${Math.floor((m.uptime_s % 86400) / 3600)}h</div></div>
        </div>`;
    } catch {
        main().innerHTML = `<div class="page-head"><h1>${t('nav.monitoring')}</h1></div>
            <div class="empty">Dostupno administratoru.</div>`;
    }
}

// ---------------------------------------------------------------- router
const ROUTES = [
    [/^#\/dashboard$/, pageDashboard],
    [/^#\/websites$/, pageWebsites],
    [/^#\/websites\/(\d+)$/, (m) => pageWebsiteDetail(Number(m[1]))],
    [/^#\/databases$/, pageDatabases],
    [/^#\/ssl$/, pageSsl],
    [/^#\/tasks$/, pageTasks],
    [/^#\/monitoring$/, pageMonitoring],
];

async function route() {
    if (!state.me) return;
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
if (state.token) {
    try { await enter(); } catch { logoutLocal(); }
} else {
    renderLogin();
}
