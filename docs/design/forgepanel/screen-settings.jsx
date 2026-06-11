// ForgePanel — Settings screen: SSL, DNS, firewall, pristup
function SettingsScreen() {
  const D = window.FP_DATA;
  const [tab, setTab] = useState('ssl');
  const [fw, setFw] = useState(D.firewall.map(f => f.on));
  const tabs = [['ssl', 'SSL certifikati', 'lock'], ['dns', 'DNS zona', 'globe'], ['fw', 'Firewall', 'shield'], ['access', 'Pristup', 'key']];

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--gap)', animation: 'fp-fadeup 0.25s ease' }}>
      <div style={{ display: 'flex', gap: 4, borderBottom: '1px solid var(--line)', paddingBottom: 0 }}>
        {tabs.map(([id, label, ic]) => (
          <button key={id} onClick={() => setTab(id)} style={{ display: 'flex', alignItems: 'center', gap: 7,
            padding: '8px 14px 10px', border: 'none', cursor: 'pointer', fontSize: 'var(--fs-base)', fontWeight: 600,
            background: 'transparent', position: 'relative',
            color: tab === id ? 'var(--ink)' : 'var(--ink-3)' }}>
            <Icon name={ic} size={14}/>{label}
            {id === 'ssl' && <Badge tone="warn">1</Badge>}
            {tab === id && <span style={{ position: 'absolute', bottom: -1, left: 10, right: 10, height: 2, background: 'var(--accent)', borderRadius: 99 }}></span>}
          </button>
        ))}
      </div>

      {tab === 'ssl' && (
        <Card pad={false} title="Certifikati" right={<><Btn small icon="refresh">Obnovi sve</Btn><Btn small primary icon="plus">Novi certifikat</Btn></>}>
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead><tr><Th>Domena</Th><Th>Izdavatelj</Th><Th>Istječe</Th><Th>Auto-renew</Th><Th>Status</Th><Th w="100"></Th></tr></thead>
            <tbody>
              {D.certs.map((c, i) => (
                <tr key={i} className="fp-row" style={{ background: c.status === 'expiring' ? 'var(--warn-soft)' : 'transparent' }}>
                  <Td><span style={{ fontWeight: 600 }}>{c.domain}</span></Td>
                  <Td style={{ color: 'var(--ink-2)' }}>{c.issuer}</Td>
                  <Td mono>{c.expires} <span style={{ color: c.days < 14 ? 'var(--danger)' : 'var(--ink-3)', fontSize: 'var(--fs-xs)' }}>({c.days} d)</span></Td>
                  <Td>{c.auto ? <Badge tone="ok">uključen</Badge> : <Badge tone="danger">isključen</Badge>}</Td>
                  <Td>{c.status === 'valid' ? <Badge tone="ok">važeći</Badge> : <Badge tone="warn">istječe uskoro</Badge>}</Td>
                  <Td right>{c.status === 'expiring'
                    ? <Btn small primary icon="refresh">Obnovi sad</Btn>
                    : <span className="fp-row-actions"><IconBtn name="dots" title="Više"/></span>}</Td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}

      {tab === 'dns' && (
        <Card pad={false} title="aurora-shop.hr — DNS zapisi" right={<><span style={{ fontSize: 'var(--fs-xs)', color: 'var(--ink-3)' }}>nameserveri: Cloudflare</span><Btn small primary icon="plus">Novi zapis</Btn></>}>
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead><tr><Th w="70">Tip</Th><Th w="110">Ime</Th><Th>Vrijednost</Th><Th right w="80">TTL</Th><Th w="90">Proxy</Th><Th w="70"></Th></tr></thead>
            <tbody>
              {D.dns.map((r, i) => (
                <tr key={i} className="fp-row">
                  <Td><Badge tone={{ A: 'ok', CNAME: 'info', MX: 'warn', TXT: 'idle', CAA: 'idle' }[r.type] || 'idle'}>{r.type}</Badge></Td>
                  <Td mono style={{ fontWeight: 600 }}>{r.name}</Td>
                  <Td mono style={{ color: 'var(--ink-2)', maxWidth: 380, overflow: 'hidden', textOverflow: 'ellipsis' }}>{r.value}</Td>
                  <Td right mono style={{ color: 'var(--ink-3)' }}>{r.ttl}</Td>
                  <Td><Toggle small on={r.proxied}/></Td>
                  <Td right><span className="fp-row-actions"><IconBtn name="dots" title="Uredi"/></span></Td>
                </tr>
              ))}
            </tbody>
          </table>
          <div style={{ padding: '10px 14px', display: 'flex', gap: 8, alignItems: 'center', fontSize: 'var(--fs-xs)', color: 'var(--ink-3)' }}>
            <Icon name="check" size={12} style={{ color: 'var(--ok)' }}/> DNSSEC aktivan · SPF, DKIM i DMARC ispravno konfigurirani
          </div>
        </Card>
      )}

      {tab === 'fw' && (
        <div style={{ display: 'grid', gridTemplateColumns: '1.4fr 1fr', gap: 'var(--gap)', alignItems: 'start' }}>
          <Card pad={false} title="Pravila" right={<Btn small primary icon="plus">Novo pravilo</Btn>}>
            {D.firewall.map((f, i) => (
              <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '11px 14px', borderBottom: '1px solid var(--line-2)' }}>
                <Icon name="shield" size={15} style={{ color: fw[i] ? 'var(--accent)' : 'var(--ink-3)' }}/>
                <div>
                  <div style={{ fontWeight: 600, fontSize: 'var(--fs-base)' }}>{f.rule}</div>
                  <div className="num" style={{ fontSize: 'var(--fs-xs)', color: 'var(--ink-3)' }}>{f.detail}</div>
                </div>
                <span style={{ marginLeft: 'auto' }}>
                  <Toggle on={fw[i]} onChange={v => setFw(arr => arr.map((x, j) => j === i ? v : x))}/>
                </span>
              </div>
            ))}
          </Card>
          <Card title="Zadnja 24 h">
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
              {[['Blokirano IP-ova', '312'], ['SSH pokušaji', '1.840'], ['WAF blokade', '94'], ['Rate-limit hit', '57']].map(([k, v]) => (
                <div key={k} style={{ background: 'var(--surface-2)', border: '1px solid var(--line-2)', borderRadius: 8, padding: '10px 12px' }}>
                  <div className="num" style={{ fontSize: 19, fontWeight: 600 }}>{v}</div>
                  <div style={{ fontSize: 'var(--fs-xs)', color: 'var(--ink-3)', marginTop: 2 }}>{k}</div>
                </div>
              ))}
            </div>
            <div style={{ marginTop: 12, fontSize: 'var(--fs-sm)', color: 'var(--ink-2)', lineHeight: 1.5, display: 'flex', gap: 8 }}>
              <Icon name="sparkle" size={13} style={{ color: 'var(--accent)', flexShrink: 0, marginTop: 2 }}/>
              <span>92% blokiranih pokušaja dolazi s 4 ASN-a. Forge AI može predložiti GeoIP pravilo.</span>
            </div>
          </Card>
        </div>
      )}

      {tab === 'access' && (
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--gap)', alignItems: 'start' }}>
          <Card pad={false} title="SSH ključevi" right={<Btn small primary icon="plus">Dodaj ključ</Btn>}>
            {[['marko@macbook-pro', 'ed25519', 'prije 2 h', true], ['marko@thinkpad', 'ed25519', 'prije 3 d', true], ['deploy-bot (CI)', 'rsa-4096', 'prije 18 min', true], ['ana@imac (bivši dev)', 'rsa-2048', 'prije 8 mj', false]].map(([name, type, used, ok], i) => (
              <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '10px 14px', borderBottom: '1px solid var(--line-2)' }}>
                <Icon name="key" size={14} style={{ color: ok ? 'var(--ink-2)' : 'var(--danger)' }}/>
                <div>
                  <div style={{ fontWeight: 600, fontSize: 'var(--fs-sm)' }}>{name}</div>
                  <div className="num" style={{ fontSize: 'var(--fs-xs)', color: 'var(--ink-3)' }}>{type} · korišten {used}</div>
                </div>
                <span style={{ marginLeft: 'auto' }}>{ok ? <Badge tone="ok">aktivan</Badge> : <Btn small danger>Opozovi</Btn>}</span>
              </div>
            ))}
          </Card>
          <Card pad={false} title="API tokeni" right={<Btn small primary icon="plus">Novi token</Btn>}>
            {[['forge-cli', 'puni pristup', 'fp_live_••••8a2c'], ['github-actions', 'deploy:write', 'fp_live_••••91bd'], ['monitoring-ro', 'metrics:read', 'fp_live_••••f04e']].map(([name, scope, tok], i) => (
              <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '10px 14px', borderBottom: '1px solid var(--line-2)' }}>
                <Icon name="terminal" size={14} style={{ color: 'var(--ink-2)' }}/>
                <div>
                  <div style={{ fontWeight: 600, fontSize: 'var(--fs-sm)' }}>{name}</div>
                  <div style={{ fontSize: 'var(--fs-xs)', color: 'var(--ink-3)' }}>{scope}</div>
                </div>
                <span className="num" style={{ marginLeft: 'auto', fontSize: 'var(--fs-xs)', color: 'var(--ink-3)' }}>{tok}</span>
              </div>
            ))}
            <div style={{ padding: '10px 14px', fontSize: 'var(--fs-xs)', color: 'var(--ink-3)' }}>
              2FA obavezan za sve članove · zadnja revizija pristupa: prije 12 d
            </div>
          </Card>
        </div>
      )}
    </div>
  );
}

window.SettingsScreen = SettingsScreen;
