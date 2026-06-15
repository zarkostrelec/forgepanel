// Javna status stranica — bez logina, čita token iz /status/<token> i prikazuje
// dostupnost domena, response time i SSL istek. Samo SVOJE (po pretplati tokena).

const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const root = document.getElementById('status');
const token = location.pathname.split('/').filter(Boolean).pop() || '';

const fmtDate = (s) => { try { return new Intl.DateTimeFormat('hr-HR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(s)); } catch { return s; } };

function siteRow(s) {
    const up = s.uptime === 'up';
    const down = s.uptime === 'down' || s.status === 'error';
    const tone = down ? 'err' : (up ? 'ok' : 'warn');
    const label = down ? 'Nedostupno' : (up ? 'Dostupno' : 'Nepoznato');
    const ssl = s.ssl_days == null ? '' :
        `<span class="badge ${s.ssl_days < 14 ? 'warn' : 'ok'}" title="SSL istek">SSL ${s.ssl_days}d</span>`;
    const rt = s.response_ms != null ? `<span class="mono small">${s.response_ms} ms</span>` : '';
    return `<tr>
        <td class="mono">${esc(s.domain)}</td>
        <td><span class="badge ${tone}">${label}</span></td>
        <td class="num">${rt}</td>
        <td class="num">${ssl}</td>
    </tr>`;
}

function render(d) {
    if (d.accent) document.documentElement.style.setProperty('--accent', d.accent);
    document.title = `${d.panel_name} — Status`;
    const sites = d.sites || [];
    const allUp = sites.length && sites.every((s) => s.uptime !== 'down' && s.status !== 'error');
    root.innerHTML = `
    <div class="status-wrap">
        <header class="status-head">
            <h1>${esc(d.panel_name)}</h1>
            <span class="badge ${allUp ? 'ok' : (sites.length ? 'warn' : 'info')}">
                ${sites.length ? (allUp ? 'Svi sustavi rade' : 'Smetnje u radu') : 'Nema stranica'}</span>
        </header>
        <div class="card flush">
            <table class="data"><thead><tr>
                <th>Domena</th><th>Status</th><th class="num">Odziv</th><th class="num">SSL</th>
            </tr></thead><tbody>${sites.map(siteRow).join('')}</tbody></table>
        </div>
        <footer class="status-foot small">Ažurirano ${fmtDate(d.generated_at)}</footer>
    </div>`;
}

fetch(`/api/v1/status/public/${encodeURIComponent(token)}`)
    .then((r) => r.json())
    .then((j) => {
        if (!j.ok) { root.innerHTML = `<div class="status-wrap"><div class="empty">Status stranica nije pronađena.</div></div>`; return; }
        render(j.data);
    })
    .catch(() => { root.innerHTML = `<div class="status-wrap"><div class="empty">Greška pri dohvaćanju statusa.</div></div>`; });

// Osvježi svakih 60 s
setInterval(() => location.reload(), 60000);
