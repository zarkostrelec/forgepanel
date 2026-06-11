// ForgePanel — chrome: rail, topbar, command palette, AI drawer
const NAV = [
  { id: 'dashboard', icon: 'grid', label: 'Pregled', key: 'D' },
  { id: 'sites', icon: 'globe', label: 'Siteovi', key: 'S' },
  { id: 'files', icon: 'folder', label: 'Datoteke', key: 'F' },
  { id: 'monitoring', icon: 'pulse', label: 'Monitoring', key: 'M' },
  { id: 'settings', icon: 'shield', label: 'SSL · DNS · Sigurnost', key: 'P' },
];

function Rail({ view, setView }) {
  const [hov, setHov] = useState(null);
  return (
    <nav style={{ width: 56, background: 'var(--chrome)', display: 'flex', flexDirection: 'column', alignItems: 'center',
      padding: '12px 0', gap: 4, flexShrink: 0, position: 'relative', zIndex: 30 }}>
      {/* logo mark */}
      <div style={{ width: 32, height: 32, borderRadius: 8, background: 'var(--accent)', display: 'grid', placeItems: 'center',
        marginBottom: 14, boxShadow: '0 0 18px var(--accent-glow)' }}>
        <Icon name="zap" size={17} sw={2} style={{ color: '#fff' }}/>
      </div>
      {NAV.map(n => {
        const active = view === n.id;
        return (
          <div key={n.id} style={{ position: 'relative' }}
            onMouseEnter={() => setHov(n.id)} onMouseLeave={() => setHov(null)}>
            <button onClick={() => setView(n.id)} aria-label={n.label}
              style={{ width: 40, height: 38, display: 'grid', placeItems: 'center', borderRadius: 9, border: 'none',
                cursor: 'pointer', position: 'relative',
                background: active ? 'var(--chrome-3)' : 'transparent',
                color: active ? '#fff' : 'var(--chrome-ink-2)', transition: 'background 0.15s, color 0.15s' }}>
              <Icon name={n.icon} size={18}/>
              {active && <span style={{ position: 'absolute', left: -8, top: 9, bottom: 9, width: 2.5, borderRadius: 99,
                background: 'var(--accent)' }}></span>}
            </button>
            {hov === n.id && (
              <div style={{ position: 'absolute', left: 50, top: 6, background: 'var(--chrome-2)', color: 'var(--chrome-ink)',
                padding: '5px 10px', borderRadius: 7, fontSize: 'var(--fs-sm)', whiteSpace: 'nowrap', zIndex: 99,
                border: '1px solid var(--chrome-line)', boxShadow: 'var(--shadow-2)', display: 'flex', gap: 8, alignItems: 'center' }}>
                {n.label} <span style={{ fontFamily: 'var(--font-mono)', fontSize: 10, color: 'var(--chrome-ink-2)' }}>G {n.key}</span>
              </div>
            )}
          </div>
        );
      })}
      <div style={{ flex: 1 }}></div>
      <div style={{ width: 30, height: 1, background: 'var(--chrome-line)', margin: '6px 0' }}></div>
      <button style={{ width: 40, height: 38, display: 'grid', placeItems: 'center', borderRadius: 9, border: 'none',
        cursor: 'pointer', background: 'transparent', color: 'var(--chrome-ink-2)' }} aria-label="Terminal">
        <Icon name="terminal" size={18}/>
      </button>
      <div style={{ width: 30, height: 30, borderRadius: 99, background: 'linear-gradient(135deg, oklch(0.62 0.125 163), oklch(0.5 0.1 220))',
        display: 'grid', placeItems: 'center', color: '#fff', fontSize: 11, fontWeight: 700, marginTop: 6, cursor: 'pointer' }}>MK</div>
    </nav>
  );
}

function TopBar({ crumbs, onPalette, onAI, aiOpen }) {
  const [hovS, setHovS] = useState(false);
  return (
    <header style={{ height: 48, display: 'flex', alignItems: 'center', gap: 12, padding: '0 16px',
      borderBottom: '1px solid var(--line)', background: 'var(--surface)', flexShrink: 0 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 7, fontSize: 'var(--fs-base)', minWidth: 0 }}>
        <Dot tone="ok" pulse/>
        <span style={{ fontWeight: 650 }}>fra1-prod-01</span>
        <span className="num" style={{ color: 'var(--ink-3)', fontSize: 'var(--fs-sm)' }}>157.90.224.18</span>
        {crumbs.map((c, i) => (
          <React.Fragment key={i}>
            <Icon name="chevR" size={11} style={{ color: 'var(--ink-3)' }}/>
            <span style={{ color: i === crumbs.length - 1 ? 'var(--ink)' : 'var(--ink-2)', fontWeight: i === crumbs.length - 1 ? 600 : 400 }}>{c}</span>
          </React.Fragment>
        ))}
      </div>

      <button onClick={onPalette} onMouseEnter={() => setHovS(true)} onMouseLeave={() => setHovS(false)}
        style={{ marginLeft: 'auto', display: 'flex', alignItems: 'center', gap: 8, width: 320, height: 30,
          padding: '0 10px', borderRadius: 8, border: '1px solid var(--line)', cursor: 'pointer',
          background: hovS ? 'var(--surface-2)' : 'var(--bg)', color: 'var(--ink-3)', fontSize: 'var(--fs-sm)' }}>
        <Icon name="search" size={13}/>
        <span>Naredba, site, datoteka, akcija…</span>
        <span style={{ marginLeft: 'auto', display: 'flex', gap: 4 }}><Kbd>⌘</Kbd><Kbd>K</Kbd></span>
      </button>

      <button onClick={onAI} title="AI asistent (⌘J)"
        style={{ display: 'flex', alignItems: 'center', gap: 6, height: 30, padding: '0 11px', borderRadius: 8,
          cursor: 'pointer', fontSize: 'var(--fs-sm)', fontWeight: 600, transition: 'all 0.15s',
          border: aiOpen ? '1px solid var(--accent)' : '1px solid var(--line)',
          background: aiOpen ? 'var(--accent-soft)' : 'var(--surface)',
          color: aiOpen ? 'var(--accent-deep)' : 'var(--ink-2)' }}>
        <Icon name="sparkle" size={14}/> Forge AI
      </button>

      <IconBtn name="bell" title="Obavijesti"/>
    </header>
  );
}

/* ---------- command palette ---------- */
const PALETTE_ITEMS = [
  { group: 'Akcije', icon: 'play', label: 'Restartaj servis…', hint: 'nginx, php-fpm, postgres…', kbd: 'R' },
  { group: 'Akcije', icon: 'refresh', label: 'Pokreni deploy', hint: 'api.fintrack.io → main', kbd: '⇧D' },
  { group: 'Akcije', icon: 'lock', label: 'Obnovi SSL certifikat', hint: 'legacy.kontaplan.hr — istječe za 9 d', warn: true },
  { group: 'Akcije', icon: 'download', label: 'Backup snapshot sada', hint: 'fra1-prod-01 → S3' },
  { group: 'Navigacija', icon: 'grid', label: 'Idi na Pregled', kbd: 'G D', nav: 'dashboard' },
  { group: 'Navigacija', icon: 'globe', label: 'Idi na Siteove', kbd: 'G S', nav: 'sites' },
  { group: 'Navigacija', icon: 'folder', label: 'Idi na Datoteke', kbd: 'G F', nav: 'files' },
  { group: 'Navigacija', icon: 'pulse', label: 'Idi na Monitoring', kbd: 'G M', nav: 'monitoring' },
  { group: 'Navigacija', icon: 'shield', label: 'Idi na SSL · DNS · Sigurnost', kbd: 'G P', nav: 'settings' },
  { group: 'Siteovi', icon: 'globe', label: 'aurora-shop.hr', hint: 'WooCommerce · PHP 8.3', nav: 'sites' },
  { group: 'Siteovi', icon: 'globe', label: 'api.fintrack.io', hint: 'Node 22 · 156k zahtjeva/dan', nav: 'sites' },
  { group: 'AI', icon: 'sparkle', label: 'Pitaj Forge AI…', hint: '"zašto je site spor?"', ai: true },
];

function CommandPalette({ open, onClose, setView, openAI }) {
  const [q, setQ] = useState('');
  const [sel, setSel] = useState(0);
  const inputRef = useRef();
  const items = useMemo(() => PALETTE_ITEMS.filter(i =>
    (i.label + ' ' + (i.hint || '')).toLowerCase().includes(q.toLowerCase())), [q]);

  useEffect(() => { if (open) { setQ(''); setSel(0); setTimeout(() => inputRef.current && inputRef.current.focus(), 30); } }, [open]);
  useEffect(() => { setSel(0); }, [q]);

  const run = (item) => {
    if (!item) return;
    if (item.nav) setView(item.nav);
    if (item.ai) openAI();
    onClose();
  };

  if (!open) return null;
  let lastGroup = null;
  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgb(10 13 18 / 0.45)', zIndex: 100,
      display: 'flex', justifyContent: 'center', paddingTop: '14vh', backdropFilter: 'blur(2px)' }}>
      <div onClick={e => e.stopPropagation()} style={{ width: 620, maxHeight: '60vh', background: 'var(--surface)',
        borderRadius: 14, boxShadow: 'var(--shadow-pop)', border: '1px solid var(--line)', overflow: 'hidden',
        display: 'flex', flexDirection: 'column', animation: 'fp-pop 0.16s ease', alignSelf: 'flex-start' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '13px 16px', borderBottom: '1px solid var(--line-2)' }}>
          <Icon name="search" size={16} style={{ color: 'var(--ink-3)' }}/>
          <input ref={inputRef} value={q} onChange={e => setQ(e.target.value)}
            onKeyDown={e => {
              if (e.key === 'ArrowDown') { e.preventDefault(); setSel(s => Math.min(s + 1, items.length - 1)); }
              if (e.key === 'ArrowUp') { e.preventDefault(); setSel(s => Math.max(s - 1, 0)); }
              if (e.key === 'Enter') run(items[sel]);
              if (e.key === 'Escape') onClose();
            }}
            placeholder="Upiši naredbu ili pretraži…"
            style={{ flex: 1, border: 'none', outline: 'none', fontSize: 15, background: 'transparent', color: 'var(--ink)' }}/>
          <Kbd>esc</Kbd>
        </div>
        <div style={{ overflowY: 'auto', padding: '6px 6px 10px' }}>
          {items.length === 0 && <div style={{ padding: 24, textAlign: 'center', color: 'var(--ink-3)' }}>Nema rezultata za „{q}"</div>}
          {items.map((item, i) => {
            const showGroup = item.group !== lastGroup; lastGroup = item.group;
            return (
              <React.Fragment key={i}>
                {showGroup && <div style={{ fontSize: 'var(--fs-xs)', fontWeight: 650, textTransform: 'uppercase',
                  letterSpacing: '0.05em', color: 'var(--ink-3)', padding: '10px 12px 4px' }}>{item.group}</div>}
                <div onClick={() => run(item)} onMouseEnter={() => setSel(i)}
                  style={{ display: 'flex', alignItems: 'center', gap: 11, padding: '8px 12px', borderRadius: 8,
                    cursor: 'pointer', background: sel === i ? 'var(--accent-soft)' : 'transparent' }}>
                  <span style={{ width: 26, height: 26, borderRadius: 7, display: 'grid', placeItems: 'center',
                    background: sel === i ? 'var(--accent)' : 'var(--surface-3)',
                    color: sel === i ? '#fff' : item.warn ? 'var(--warn)' : 'var(--ink-2)' }}>
                    <Icon name={item.icon} size={14}/>
                  </span>
                  <span style={{ fontWeight: 550 }}>{item.label}</span>
                  {item.hint && <span style={{ color: 'var(--ink-3)', fontSize: 'var(--fs-sm)', overflow: 'hidden',
                    textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{item.hint}</span>}
                  <span style={{ marginLeft: 'auto' }}>{item.kbd && <Kbd>{item.kbd}</Kbd>}</span>
                </div>
              </React.Fragment>
            );
          })}
        </div>
      </div>
    </div>
  );
}

/* ---------- AI drawer ---------- */
const AI_THREAD = [
  { who: 'user', text: 'Zašto je aurora-shop.hr spor zadnjih sat vremena?' },
  { who: 'ai', text: 'Analizirao sam zadnjih 60 min. Tri nalaza, poredano po utjecaju:', findings: [
    { sev: 'danger', title: 'apache2 OOM restart u 13:58', body: 'Legacy proces na :8080 potrošio je 92% RAM-a prije restarta — php-fpm pool „aurora" je u tom periodu čekao na memoriju (avg TTFB 2.4s → inače 180ms).' },
    { sev: 'warn', title: 'Spori upit u WooCommerceu', body: 'SELECT na wp_postmeta bez indeksa — 312 izvršavanja, avg 840ms. Predlažem composite indeks (meta_key, post_id).' },
    { sev: 'info', title: 'OPcache 96% pun', body: 'opcache.memory_consumption=128M je premalo za 2 PHP site-a. Preporuka: 256M.' },
  ]},
];

function AIDrawer({ open, onClose }) {
  const [input, setInput] = useState('');
  if (!open) return null;
  return (
    <aside className="dark-scroll" style={{ width: 380, flexShrink: 0, background: 'var(--surface)', borderLeft: '1px solid var(--line)',
      display: 'flex', flexDirection: 'column', animation: 'fp-fadeup 0.18s ease', zIndex: 20, minHeight: 0 }}>
      <header style={{ display: 'flex', alignItems: 'center', gap: 9, padding: '12px 14px', borderBottom: '1px solid var(--line-2)' }}>
        <span style={{ width: 26, height: 26, borderRadius: 8, background: 'var(--accent)', display: 'grid', placeItems: 'center', color: '#fff' }}>
          <Icon name="sparkle" size={14}/>
        </span>
        <div>
          <div style={{ fontWeight: 650, fontSize: 'var(--fs-base)' }}>Forge AI</div>
          <div style={{ fontSize: 'var(--fs-xs)', color: 'var(--ink-3)' }}>vidi metrike, logove i konfiguraciju</div>
        </div>
        <button onClick={onClose} style={{ marginLeft: 'auto', border: 'none', background: 'transparent', cursor: 'pointer',
          color: 'var(--ink-3)', display: 'grid', placeItems: 'center', width: 26, height: 26 }}><Icon name="x" size={15}/></button>
      </header>

      <div style={{ flex: 1, overflowY: 'auto', padding: 14, display: 'flex', flexDirection: 'column', gap: 12 }}>
        {AI_THREAD.map((m, i) => m.who === 'user' ? (
          <div key={i} style={{ alignSelf: 'flex-end', maxWidth: '88%', background: 'var(--chrome-2)', color: 'var(--chrome-ink)',
            padding: '8px 12px', borderRadius: '12px 12px 3px 12px', fontSize: 'var(--fs-base)', lineHeight: 1.45 }}>{m.text}</div>
        ) : (
          <div key={i} style={{ maxWidth: '100%', display: 'flex', flexDirection: 'column', gap: 8 }}>
            <div style={{ fontSize: 'var(--fs-base)', lineHeight: 1.5 }}>{m.text}</div>
            {m.findings && m.findings.map((f, j) => (
              <div key={j} style={{ border: '1px solid var(--line)', borderRadius: 9, padding: '9px 11px', background: 'var(--surface-2)' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 7, marginBottom: 4 }}>
                  <Dot tone={f.sev}/>
                  <span style={{ fontWeight: 650, fontSize: 'var(--fs-sm)' }}>{f.title}</span>
                </div>
                <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--ink-2)', lineHeight: 1.5 }}>{f.body}</div>
              </div>
            ))}
            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
              <Btn small primary icon="zap">Primijeni sva 3 fixa</Btn>
              <Btn small icon="terminal">Pokaži naredbe</Btn>
            </div>
          </div>
        ))}
      </div>

      <div style={{ padding: 12, borderTop: '1px solid var(--line-2)' }}>
        <div style={{ display: 'flex', gap: 6, marginBottom: 8, flexWrap: 'wrap' }}>
          {['Provjeri sigurnost', 'Optimiziraj bazu', 'Zašto 502?'].map(s => (
            <button key={s} style={{ fontSize: 'var(--fs-xs)', padding: '3px 9px', borderRadius: 99, border: '1px solid var(--line)',
              background: 'var(--surface)', color: 'var(--ink-2)', cursor: 'pointer' }}>{s}</button>
          ))}
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <input value={input} onChange={e => setInput(e.target.value)} placeholder="Pitaj o serveru…"
            style={{ flex: 1, border: '1px solid var(--line)', borderRadius: 8, padding: '8px 11px', fontSize: 'var(--fs-base)',
              outline: 'none', background: 'var(--bg)', color: 'var(--ink)' }}/>
          <Btn primary icon="arrowUR" onClick={() => setInput('')}> </Btn>
        </div>
      </div>
    </aside>
  );
}

Object.assign(window, { Rail, TopBar, CommandPalette, AIDrawer, NAV });
