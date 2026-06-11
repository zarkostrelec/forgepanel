// ForgePanel — Sites screen
function SitesScreen() {
  const D = window.FP_DATA;
  const [sel, setSel] = useState('s1');
  const [filter, setFilter] = useState('svi');
  const site = D.sites.find(s => s.id === sel);
  const spark1 = useLive(30, 55, 16);

  const filtered = D.sites.filter(s => filter === 'svi' ? true : filter === 'problemi' ? s.status === 'warning' || s.ssl === 'expiring' : s.status === 'live');

  return (
    <div style={{ display: 'grid', gridTemplateColumns: '1fr 340px', gap: 'var(--gap)', alignItems: 'start', animation: 'fp-fadeup 0.25s ease' }}>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--gap)', minWidth: 0 }}>
        {/* toolbar */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          {['svi', 'live', 'problemi'].map(f => (
            <button key={f} onClick={() => setFilter(f)} style={{ padding: '5px 13px', borderRadius: 99, fontSize: 'var(--fs-sm)',
              fontWeight: 600, cursor: 'pointer', textTransform: 'capitalize',
              border: filter === f ? '1px solid var(--accent)' : '1px solid var(--line)',
              background: filter === f ? 'var(--accent-soft)' : 'var(--surface)',
              color: filter === f ? 'var(--accent-deep)' : 'var(--ink-2)' }}>
              {f}{f === 'problemi' && <span style={{ marginLeft: 5, background: 'var(--warn)', color: '#fff', borderRadius: 99,
                padding: '0 5px', fontSize: 10 }}>2</span>}
            </button>
          ))}
          <span style={{ marginLeft: 'auto', fontSize: 'var(--fs-sm)', color: 'var(--ink-3)' }} className="num">{filtered.length} site-ova · 6 domena</span>
          <Btn primary icon="plus">Novi site</Btn>
        </div>

        <Card pad={false}>
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead><tr><Th>Site</Th><Th>Stack</Th><Th right>Promet (7d)</Th><Th>SSL</Th><Th>Deploy</Th><Th right>Disk</Th></tr></thead>
            <tbody>
              {filtered.map(s => {
                const active = s.id === sel;
                return (
                  <tr key={s.id} onClick={() => setSel(s.id)} className="fp-row" style={{ cursor: 'pointer',
                    background: active ? 'var(--accent-soft)' : 'transparent' }}>
                    <Td>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 9 }}>
                        <Dot tone={s.status === 'live' ? 'ok' : s.status === 'warning' ? 'warn' : 'idle'}/>
                        <div>
                          <div style={{ fontWeight: 600 }}>{s.name}</div>
                          <div style={{ fontSize: 'var(--fs-xs)', color: 'var(--ink-3)' }}>{s.type}</div>
                        </div>
                      </div>
                    </Td>
                    <Td mono style={{ color: 'var(--ink-2)' }}>{s.stack}</Td>
                    <Td right>
                      <div style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                        <span className="num" style={{ fontSize: 'var(--fs-sm)' }}>{s.traffic >= 1000 ? (s.traffic / 1000).toFixed(1) + 'k' : s.traffic || '—'}</span>
                        <span className="num" style={{ fontSize: 'var(--fs-xs)', color: s.traffic7.startsWith('+') ? 'var(--ok)' : s.traffic7.startsWith('-') ? 'var(--danger)' : 'var(--ink-3)' }}>{s.traffic7}</span>
                      </div>
                    </Td>
                    <Td>{s.ssl === 'valid' ? <Badge tone="ok">TLS · {s.sslDays}d</Badge> : <Badge tone="warn">istječe {s.sslDays}d</Badge>}</Td>
                    <Td>
                      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 'var(--fs-sm)', color: 'var(--ink-2)' }}>
                        {s.branch !== '—' && <Icon name="branch" size={12} style={{ color: 'var(--ink-3)' }}/>}
                        {s.branch !== '—' ? <span className="num" style={{ fontSize: 'var(--fs-xs)' }}>{s.branch}</span> : null}
                        <span style={{ color: 'var(--ink-3)', fontSize: 'var(--fs-xs)' }}>{s.deploy}</span>
                      </span>
                    </Td>
                    <Td right mono style={{ color: 'var(--ink-2)' }}>{s.disk}</Td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </Card>
      </div>

      {/* detail panel */}
      {site && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--gap)' }}>
          <Card>
            <div style={{ display: 'flex', alignItems: 'center', gap: 9, marginBottom: 4 }}>
              <Dot tone={site.status === 'live' ? 'ok' : 'warn'} pulse/>
              <h2 style={{ margin: 0, fontSize: 16, fontWeight: 700 }}>{site.name}</h2>
              <a href="#" onClick={e => e.preventDefault()} style={{ marginLeft: 'auto', color: 'var(--accent-deep)', display: 'flex' }}><Icon name="arrowUR" size={15}/></a>
            </div>
            <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--ink-3)', marginBottom: 12 }} className="num">{site.stack} · {site.type}</div>
            <div style={{ marginBottom: 12 }}>
              <Spark data={spark1} w={296} h={44} color="var(--accent)"/>
              <div style={{ fontSize: 'var(--fs-xs)', color: 'var(--ink-3)', marginTop: 4 }} className="num">posjete · zadnja 24 h</div>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8, fontSize: 'var(--fs-sm)' }}>
              {[['Promet 7d', site.traffic >= 1000 ? (site.traffic / 1000).toFixed(1) + 'k' : site.traffic], ['Trend', site.traffic7],
                ['PHP', site.php], ['Disk', site.disk]].map(([k, v]) => (
                <div key={k} style={{ background: 'var(--surface-2)', border: '1px solid var(--line-2)', borderRadius: 8, padding: '7px 10px' }}>
                  <div style={{ fontSize: 'var(--fs-xs)', color: 'var(--ink-3)' }}>{k}</div>
                  <div className="num" style={{ fontWeight: 600 }}>{v}</div>
                </div>
              ))}
            </div>
            <div style={{ display: 'flex', gap: 6, marginTop: 12, flexWrap: 'wrap' }}>
              <Btn small primary icon="refresh">Deploy</Btn>
              <Btn small icon="folder">Datoteke</Btn>
              <Btn small icon="terminal">SSH</Btn>
              <Btn small icon="dots"></Btn>
            </div>
          </Card>

          <Card title="Domene i SSL" pad={false}>
            {[{ d: site.name, primary: true }, { d: 'www.' + site.name.replace('staging.', '') }].map((x, i) => (
              <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 14px', borderBottom: '1px solid var(--line-2)' }}>
                <Icon name="globe" size={13} style={{ color: 'var(--ink-3)' }}/>
                <span style={{ fontSize: 'var(--fs-sm)', fontWeight: x.primary ? 600 : 400 }}>{x.d}</span>
                {x.primary && <Badge tone="idle">primarna</Badge>}
                <span style={{ marginLeft: 'auto' }}><Badge tone={site.ssl === 'valid' ? 'ok' : 'warn'}>
                  <Icon name="lock" size={9} style={{ verticalAlign: '-1px', marginRight: 3 }}/>{site.ssl === 'valid' ? 'aktivan' : `${site.sslDays} d`}</Badge></span>
              </div>
            ))}
            <div style={{ padding: '9px 14px' }}>
              <Btn small icon="plus">Dodaj domenu</Btn>
            </div>
          </Card>

          <Card title="Brze radnje" pad={false}>
            {[['Očisti cache', 'zap'], ['Restartaj PHP pool', 'refresh'], ['Backup site-a', 'download'], ['Maintenance mode', 'lock']].map(([l, ic], i) => (
              <button key={i} className="fp-quick" style={{ display: 'flex', alignItems: 'center', gap: 10, width: '100%',
                padding: '9px 14px', border: 'none', borderBottom: '1px solid var(--line-2)', background: 'transparent',
                cursor: 'pointer', fontSize: 'var(--fs-sm)', fontWeight: 550, color: 'var(--ink)', textAlign: 'left' }}>
                <Icon name={ic} size={14} style={{ color: 'var(--ink-2)' }}/>{l}
                <Icon name="chevR" size={12} style={{ marginLeft: 'auto', color: 'var(--ink-3)' }}/>
              </button>
            ))}
          </Card>
        </div>
      )}
    </div>
  );
}

window.SitesScreen = SitesScreen;
