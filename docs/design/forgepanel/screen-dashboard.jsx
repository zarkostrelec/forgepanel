// ForgePanel — Dashboard screen
function MetricCard({ label, value, sub, data, tone = 'ok', unit }) {
  const color = { ok: 'var(--accent)', warn: 'var(--warn)', danger: 'var(--danger)', info: 'var(--info)' }[tone];
  return (
    <div style={{ background: 'var(--surface)', border: '1px solid var(--line)', borderRadius: 'var(--radius)',
      boxShadow: 'var(--shadow-1)', padding: '12px 14px', display: 'flex', flexDirection: 'column', gap: 6, minWidth: 0 }}>
      <div style={{ fontSize: 'var(--fs-xs)', fontWeight: 650, textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--ink-3)' }}>{label}</div>
      <div style={{ display: 'flex', alignItems: 'flex-end', gap: 10, justifyContent: 'space-between' }}>
        <div>
          <span className="num" style={{ fontSize: 22, fontWeight: 600, lineHeight: 1 }}>{value}</span>
          {unit && <span className="num" style={{ fontSize: 'var(--fs-sm)', color: 'var(--ink-3)', marginLeft: 2 }}>{unit}</span>}
          <div className="num" style={{ fontSize: 'var(--fs-xs)', color: 'var(--ink-3)', marginTop: 3 }}>{sub}</div>
        </div>
        {data && <Spark data={data} w={84} h={30} color={color}/>}
      </div>
    </div>
  );
}

/* --- server topology --- */
function TopoNode({ x, y, w = 108, label, sub, tone = 'ok', icon }) {
  return (
    <g transform={`translate(${x},${y})`}>
      <rect width={w} height="44" rx="9" fill="var(--surface)" stroke="var(--line-strong)"/>
      <circle cx="16" cy="22" r="3.5" fill={{ ok: 'var(--ok)', warn: 'var(--warn)', danger: 'var(--danger)', idle: 'var(--ink-3)' }[tone]}/>
      <text x="28" y="19" fontSize="11" fontWeight="650" fill="var(--ink)" fontFamily="var(--font-ui)">{label}</text>
      <text x="28" y="33" fontSize="9.5" fill="var(--ink-3)" fontFamily="var(--font-mono)">{sub}</text>
    </g>
  );
}

function TopoEdge({ d, tone = 'ok', dim }) {
  const c = { ok: 'var(--accent)', warn: 'var(--warn)', idle: 'var(--line-strong)' }[tone];
  return (
    <g>
      <path d={d} fill="none" stroke="var(--line-strong)" strokeWidth="1" opacity="0.5"/>
      {!dim && <path d={d} fill="none" stroke={c} strokeWidth="1.4" strokeDasharray="3 13" strokeLinecap="round"
        style={{ animation: 'fp-dash 1.1s linear infinite' }} opacity="0.9"/>}
    </g>
  );
}

function Topology() {
  return (
    <svg viewBox="0 0 740 248" style={{ width: '100%', display: 'block' }}>
      <TopoEdge d="M118 124 H 158"/>
      <TopoEdge d="M276 124 H 308"/>
      <TopoEdge d="M426 124 C 460 124, 450 54, 490 54"/>
      <TopoEdge d="M426 124 H 490"/>
      <TopoEdge d="M426 124 C 460 124, 450 194, 490 194" tone="idle" dim/>
      <TopoEdge d="M608 54 C 650 54, 630 88, 658 88"/>
      <TopoEdge d="M608 124 C 650 124, 630 96, 658 92" />
      <TopoEdge d="M608 124 C 650 124, 630 152, 658 156"/>
      <TopoNode x="10" y="102" w="108" label="Internet" sub="48.2k req/h" tone="ok" />
      <TopoNode x="158" y="102" w="118" label="Cloudflare" sub="cache 71%" tone="ok"/>
      <TopoNode x="308" y="102" w="118" label="nginx LB" sub=":80 :443 · TLS1.3" tone="ok"/>
      <TopoNode x="490" y="32" w="118" label="php-fpm" sub="aurora · 8 workera" tone="ok"/>
      <TopoNode x="490" y="102" w="118" label="node · pm2" sub="fintrack ×4" tone="ok"/>
      <TopoNode x="490" y="172" w="118" label="apache2" sub=":8080 legacy" tone="warn"/>
      <TopoNode x="658" y="66" w="74" label="pg 16" sub="1.8 GB" tone="ok"/>
      <TopoNode x="658" y="134" w="74" label="redis" sub="226 MB" tone="ok"/>
    </svg>
  );
}

function EventRow({ e }) {
  const icons = { deploy: 'branch', security: 'shield', ssl: 'lock', system: 'server', backup: 'download', db: 'db' };
  return (
    <div style={{ display: 'flex', gap: 10, padding: '7px 14px', alignItems: 'flex-start', borderBottom: '1px solid var(--line-2)' }}>
      <span className="num" style={{ fontSize: 'var(--fs-xs)', color: 'var(--ink-3)', paddingTop: 2, flexShrink: 0 }}>{e.t}</span>
      <span style={{ color: { ok: 'var(--ok)', warn: 'var(--warn)', info: 'var(--info)' }[e.level], paddingTop: 1 }}>
        <Icon name={icons[e.kind] || 'activity'} size={13}/>
      </span>
      <span style={{ fontSize: 'var(--fs-sm)', lineHeight: 1.45, color: 'var(--ink-2)' }}>{e.msg}</span>
    </div>
  );
}

function DashboardScreen() {
  const cpu = useLive(40, 34, 14);
  const ram = useLive(40, 58, 6);
  const net = useLive(40, 42, 22);
  const req = useLive(40, 50, 18);
  const D = window.FP_DATA;

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--gap)', animation: 'fp-fadeup 0.25s ease' }}>
      {/* metric strip */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(5, 1fr)', gap: 'var(--gap)' }}>
        <MetricCard label="CPU" value={cpu[cpu.length - 1].toFixed(0)} unit="%" sub={`load ${D.server.load.join(' · ')}`} data={cpu}/>
        <MetricCard label="RAM" value={(D.server.ramTotal * ram[ram.length - 1] / 100).toFixed(1)} unit={`/ ${D.server.ramTotal} GB`} sub={`${ram[ram.length - 1].toFixed(0)}% iskorišteno`} data={ram} tone="info"/>
        <MetricCard label="Disk" value="412" unit="/ 640 GB" sub="NVMe · 64% · +1.2 GB/d" data={null} tone="info"/>
        <MetricCard label="Mreža" value={(net[net.length - 1] * 2.4).toFixed(0)} unit="Mbps" sub="↓ 84 · ↑ 31 Mbps" data={net} tone="info"/>
        <MetricCard label="Zahtjevi" value={(req[req.length - 1] * 9.6).toFixed(0)} unit="/s" sub="5xx: 0.02% · p95 184ms" data={req}/>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1.65fr 1fr', gap: 'var(--gap)', alignItems: 'start' }}>
        {/* topology */}
        <Card title="Topologija servera" right={<><Badge tone="ok">9/10 zdravo</Badge><Btn small icon="refresh">Osvježi</Btn></>}>
          <Topology/>
          <div style={{ display: 'flex', gap: 16, marginTop: 4, fontSize: 'var(--fs-xs)', color: 'var(--ink-3)', alignItems: 'center' }}>
            <span style={{ display: 'flex', gap: 5, alignItems: 'center' }}><Dot tone="ok"/> aktivan promet</span>
            <span style={{ display: 'flex', gap: 5, alignItems: 'center' }}><Dot tone="warn"/> degradirano</span>
            <span style={{ marginLeft: 'auto', fontFamily: 'var(--font-mono)' }}>uptime {D.server.uptime} · {D.server.os}</span>
          </div>
        </Card>

        {/* live feed */}
        <Card title="Događaji uživo" right={<span style={{ display: 'flex', alignItems: 'center', gap: 5, fontSize: 'var(--fs-xs)', color: 'var(--ink-3)' }}><Dot tone="ok" pulse/> live</span>} pad={false} style={{ maxHeight: 332, overflow: 'hidden' }}>
          <div style={{ overflowY: 'auto', maxHeight: 290 }}>
            {D.events.map((e, i) => <EventRow key={i} e={e}/>)}
          </div>
        </Card>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1.65fr 1fr', gap: 'var(--gap)', alignItems: 'start' }}>
        {/* services */}
        <Card title="Servisi" pad={false} right={<Btn small icon="plus">Dodaj servis</Btn>}>
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead><tr><Th>Servis</Th><Th>Status</Th><Th right>CPU</Th><Th right>RAM</Th><Th right>Uptime</Th><Th>Port</Th><Th w="90"></Th></tr></thead>
            <tbody>
              {D.services.map((s, i) => (
                <tr key={i} className="fp-row">
                  <Td><span style={{ display: 'flex', alignItems: 'center', gap: 8, fontWeight: 550 }}>
                    <Dot tone={s.status === 'active' ? 'ok' : 'warn'}/>{s.name}
                    <span className="num" style={{ color: 'var(--ink-3)', fontSize: 'var(--fs-xs)' }}>{s.ver}</span></span></Td>
                  <Td><Badge tone={s.status === 'active' ? 'ok' : 'warn'}>{s.status === 'active' ? 'aktivan' : 'degradiran'}</Badge></Td>
                  <Td right mono>{s.cpu.toFixed(1)}%</Td>
                  <Td right mono>{s.ram} MB</Td>
                  <Td right mono>{s.uptime}</Td>
                  <Td mono style={{ color: 'var(--ink-3)' }}>{s.port}</Td>
                  <Td right>
                    <span className="fp-row-actions" style={{ display: 'inline-flex', gap: 2 }}>
                      <IconBtn name="refresh" title="Restart"/><IconBtn name="terminal" title="Logovi"/><IconBtn name="dots" title="Više"/>
                    </span>
                  </Td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>

        {/* right column: AI insight + deploys */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--gap)' }}>
          <div style={{ border: '1px solid color-mix(in oklab, var(--accent) 35%, var(--line))', borderRadius: 'var(--radius)',
            background: 'linear-gradient(135deg, var(--accent-soft), var(--surface))', padding: 14, display: 'flex', gap: 11 }}>
            <span style={{ width: 28, height: 28, borderRadius: 8, background: 'var(--accent)', display: 'grid', placeItems: 'center', color: '#fff', flexShrink: 0 }}>
              <Icon name="sparkle" size={15}/>
            </span>
            <div style={{ minWidth: 0 }}>
              <div style={{ fontWeight: 650, marginBottom: 3 }}>Forge AI · 2 preporuke</div>
              <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--ink-2)', lineHeight: 1.5 }}>
                apache2 se restarta zbog OOM-a — predlažem migraciju legacy site-a na php-fpm. SSL za <b>legacy.kontaplan.hr</b> istječe za 9 dana.
              </div>
              <div style={{ display: 'flex', gap: 6, marginTop: 9 }}>
                <Btn small primary icon="sparkle">Otvori analizu</Btn>
                <Btn small>Kasnije</Btn>
              </div>
            </div>
          </div>

          <Card title="Zadnji deployi" pad={false}>
            {[
              { site: 'api.fintrack.io', n: '#482', sha: 'b3f19c2', t: 'prije 18 min', ok: true },
              { site: 'aurora-shop.hr', n: '#219', sha: '88ac01d', t: 'prije 2 h', ok: true },
              { site: 'studio-mono.com', n: '#77', sha: '1f0aa3e', t: 'jučer 16:40', ok: true },
              { site: 'api.fintrack.io', n: '#481', sha: 'c90d1b8', t: 'jučer 11:02', ok: false },
            ].map((d, i) => (
              <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 9, padding: '8px 14px', borderBottom: '1px solid var(--line-2)' }}>
                <Icon name={d.ok ? 'check' : 'x'} size={13} style={{ color: d.ok ? 'var(--ok)' : 'var(--danger)' }}/>
                <span style={{ fontWeight: 550, fontSize: 'var(--fs-sm)' }}>{d.site}</span>
                <span className="num" style={{ fontSize: 'var(--fs-xs)', color: 'var(--ink-3)' }}>{d.n} · {d.sha}</span>
                <span style={{ marginLeft: 'auto', fontSize: 'var(--fs-xs)', color: 'var(--ink-3)' }}>{d.t}</span>
              </div>
            ))}
          </Card>
        </div>
      </div>
    </div>
  );
}

window.DashboardScreen = DashboardScreen;
