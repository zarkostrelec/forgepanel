// ForgePanel — Monitoring screen
function MonitoringScreen() {
  const D = window.FP_DATA;
  const [range, setRange] = useState('1h');
  const cpu = useLive(60, 36, 12, 1200);
  const ram = useLive(60, 58, 5, 1600);
  const netIn = useLive(60, 48, 18, 1300);
  const netOut = useLive(60, 24, 10, 1300);
  const rt = useLive(60, 30, 14, 1500);
  const cores = [42, 31, 68, 22, 55, 38, 71, 29];

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--gap)', animation: 'fp-fadeup 0.25s ease' }}>
      {/* range selector */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
        {['15m', '1h', '6h', '24h', '7d', '30d'].map(r => (
          <button key={r} onClick={() => setRange(r)} className="num" style={{ padding: '4px 11px', borderRadius: 7,
            fontSize: 'var(--fs-sm)', fontWeight: 600, cursor: 'pointer',
            border: range === r ? '1px solid var(--accent)' : '1px solid var(--line)',
            background: range === r ? 'var(--accent-soft)' : 'var(--surface)',
            color: range === r ? 'var(--accent-deep)' : 'var(--ink-2)' }}>{r}</button>
        ))}
        <span style={{ marginLeft: 'auto', display: 'flex', alignItems: 'center', gap: 6, fontSize: 'var(--fs-xs)', color: 'var(--ink-3)' }}>
          <Dot tone="ok" pulse/> streaming · 2s rezolucija
        </span>
        <Btn small icon="download">Izvoz</Btn>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--gap)' }}>
        <Card title="CPU" right={<span className="num" style={{ fontSize: 'var(--fs-sm)', color: 'var(--ink-2)' }}>{cpu[cpu.length - 1].toFixed(0)}% · load {D.server.load[0]}</span>}>
          <BigChart data={cpu} color="var(--accent)"/>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(8, 1fr)', gap: 8, marginTop: 12 }}>
            {cores.map((c, i) => (
              <div key={i}>
                <div className="num" style={{ fontSize: 'var(--fs-xs)', color: 'var(--ink-3)', marginBottom: 3 }}>c{i} <span style={{ float: 'right' }}>{c}%</span></div>
                <Bar pct={c} tone={c > 65 ? 'warn' : 'ok'}/>
              </div>
            ))}
          </div>
        </Card>

        <Card title="Memorija" right={<span className="num" style={{ fontSize: 'var(--fs-sm)', color: 'var(--ink-2)' }}>{(32 * ram[ram.length - 1] / 100).toFixed(1)} / 32 GB</span>}>
          <BigChart data={ram} color="var(--info)"/>
          <div style={{ display: 'flex', gap: 14, marginTop: 12, fontSize: 'var(--fs-xs)', color: 'var(--ink-2)' }}>
            {[['aplikacije', '14.2 GB', 'var(--info)'], ['cache/buffer', '4.4 GB', 'var(--accent)'], ['slobodno', '13.4 GB', 'var(--line-strong)']].map(([l, v, c]) => (
              <span key={l} style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                <span style={{ width: 8, height: 8, borderRadius: 3, background: c }}></span>{l} <b className="num">{v}</b>
              </span>
            ))}
          </div>
        </Card>

        <Card title="Mreža" right={<span className="num" style={{ fontSize: 'var(--fs-sm)' }}>
          <span style={{ color: 'var(--accent-deep)' }}>↓ {(netIn[netIn.length - 1] * 2.4).toFixed(0)}</span>
          <span style={{ color: 'var(--ink-3)' }}> · </span>
          <span style={{ color: 'oklch(0.45 0.09 250)' }}>↑ {(netOut[netOut.length - 1] * 2.4).toFixed(0)} Mbps</span></span>}>
          <div style={{ position: 'relative' }}>
            <BigChart data={netIn} color="var(--accent)"/>
            <div style={{ position: 'absolute', inset: 0 }}><BigChart data={netOut} color="var(--info)" grid={0}/></div>
          </div>
        </Card>

        <Card title="Vrijeme odgovora" right={<span className="num" style={{ fontSize: 'var(--fs-sm)', color: 'var(--ink-2)' }}>p50 84ms · p95 {(rt[rt.length - 1] * 6).toFixed(0)}ms</span>}>
          <BigChart data={rt} color="var(--warn)"/>
          <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
            {[['2xx', '98.9%', 'ok'], ['3xx', '0.8%', 'info'], ['4xx', '0.28%', 'warn'], ['5xx', '0.02%', 'danger']].map(([code, v, tone]) => (
              <div key={code} style={{ flex: 1, background: 'var(--surface-2)', border: '1px solid var(--line-2)', borderRadius: 8, padding: '6px 10px' }}>
                <span style={{ fontSize: 'var(--fs-xs)', color: 'var(--ink-3)' }} className="num">{code}</span>
                <div style={{ display: 'flex', alignItems: 'center', gap: 5 }}>
                  <Dot tone={tone}/><span className="num" style={{ fontWeight: 600, fontSize: 'var(--fs-sm)' }}>{v}</span>
                </div>
              </div>
            ))}
          </div>
        </Card>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1.5fr 1fr', gap: 'var(--gap)', alignItems: 'start' }}>
        <Card title="Top procesi" pad={false} right={<span className="num" style={{ fontSize: 'var(--fs-xs)', color: 'var(--ink-3)' }}>htop · sortirano po CPU</span>}>
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead><tr><Th w="70" right>PID</Th><Th>Proces</Th><Th>Korisnik</Th><Th right>CPU</Th><Th right>RAM</Th><Th w="120"></Th></tr></thead>
            <tbody>
              {D.processes.map(p => (
                <tr key={p.pid} className="fp-row">
                  <Td right mono style={{ color: 'var(--ink-3)' }}>{p.pid}</Td>
                  <Td mono>{p.name}</Td>
                  <Td mono style={{ color: 'var(--ink-2)' }}>{p.user}</Td>
                  <Td right mono>{p.cpu.toFixed(1)}%</Td>
                  <Td right mono>{p.ram} MB</Td>
                  <Td><Bar pct={Math.min(100, p.cpu * 10)} tone={p.cpu > 6 ? 'warn' : 'ok'}/></Td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>

        <Card title="Aktivna upozorenja" pad={false} right={<Btn small icon="plus">Novo pravilo</Btn>}>
          {D.alerts.map((a, i) => (
            <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '9px 14px', borderBottom: '1px solid var(--line-2)' }}>
              <Icon name="bell" size={13} style={{ color: 'var(--ink-3)' }}/>
              <div style={{ minWidth: 0 }}>
                <div style={{ fontSize: 'var(--fs-sm)', fontWeight: 600 }}>{a.name}</div>
                <div style={{ fontSize: 'var(--fs-xs)', color: 'var(--ink-3)' }}>{a.target} → {a.channel}</div>
              </div>
              <span style={{ marginLeft: 'auto' }}><Toggle small on={a.on}/></span>
            </div>
          ))}
          <div style={{ padding: '10px 14px', fontSize: 'var(--fs-xs)', color: 'var(--ink-3)' }}>
            Zadnje okidanje: <b>CPU &gt; 85%</b> · prije 3 dana · trajalo 4 min
          </div>
        </Card>
      </div>
    </div>
  );
}

window.MonitoringScreen = MonitoringScreen;
