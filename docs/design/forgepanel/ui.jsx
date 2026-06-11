// ForgePanel — UI primitives
const { useState, useEffect, useRef, useMemo, useCallback } = React;

/* ---------- icons (simple geometric strokes) ---------- */
const FP_ICONS = {
  grid: <g><rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/></g>,
  globe: <g><circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17"/><path d="M12 3.5c2.6 2.3 2.6 14.7 0 17c-2.6-2.3-2.6-14.7 0-17z"/></g>,
  folder: <g><path d="M3.5 6.5a2 2 0 0 1 2-2h4l2 2.5h7a2 2 0 0 1 2 2v8.5a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z"/></g>,
  activity: <g><path d="M3 13h3.5l2.5-7 4 12 2.5-7H21"/></g>,
  shield: <g><path d="M12 3.5l7 2.5v6c0 4.4-3 7.5-7 8.5c-4-1-7-4.1-7-8.5v-6z"/></g>,
  terminal: <g><path d="M5 8l4 4-4 4"/><path d="M12 17h7"/></g>,
  search: <g><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></g>,
  sparkle: <g><path d="M12 4l1.8 5.4L19 11l-5.2 1.6L12 18l-1.8-5.4L5 11l5.2-1.6z"/></g>,
  bell: <g><path d="M6 16v-5a6 6 0 0 1 12 0v5l1.5 2.5h-15z"/><path d="M10 21h4"/></g>,
  gear: <g><circle cx="12" cy="12" r="3.5"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/></g>,
  chevR: <g><path d="M9 5l7 7-7 7"/></g>,
  chevD: <g><path d="M5 9l7 7 7-7"/></g>,
  plus: <g><path d="M12 5v14M5 12h14"/></g>,
  play: <g><path d="M7 5l12 7-12 7z"/></g>,
  refresh: <g><path d="M19 12a7 7 0 1 1-2-5"/><path d="M17 3v4h4"/></g>,
  lock: <g><rect x="5.5" y="10.5" width="13" height="9" rx="2"/><path d="M8.5 10.5v-3a3.5 3.5 0 0 1 7 0v3"/></g>,
  dots: <g><circle cx="5" cy="12" r="1.3" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.3" fill="currentColor" stroke="none"/><circle cx="19" cy="12" r="1.3" fill="currentColor" stroke="none"/></g>,
  file: <g><path d="M6 3.5h8l4 4v13h-12z"/><path d="M14 3.5v4h4"/></g>,
  db: <g><ellipse cx="12" cy="6" rx="7.5" ry="3"/><path d="M4.5 6v12c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3V6"/><path d="M4.5 12c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3"/></g>,
  server: <g><rect x="3.5" y="4.5" width="17" height="6.5" rx="1.5"/><rect x="3.5" y="13" width="17" height="6.5" rx="1.5"/><circle cx="7.5" cy="7.75" r="0.8" fill="currentColor" stroke="none"/><circle cx="7.5" cy="16.25" r="0.8" fill="currentColor" stroke="none"/></g>,
  x: <g><path d="M6 6l12 12M18 6L6 18"/></g>,
  check: <g><path d="M5 13l4.5 4.5L19 7"/></g>,
  arrowUR: <g><path d="M7 17L17 7M9 7h8v8"/></g>,
  branch: <g><circle cx="6" cy="6" r="2.2"/><circle cx="6" cy="18" r="2.2"/><circle cx="18" cy="8" r="2.2"/><path d="M6 8.2v7.6M18 10.2c0 4-5 3.8-9.8 5.4"/></g>,
  clock: <g><circle cx="12" cy="12" r="8.5"/><path d="M12 7v5.5l3.5 2"/></g>,
  zap: <g><path d="M13 3L5 14h6l-1 7 8-11h-6z"/></g>,
  mail: <g><rect x="3.5" y="5.5" width="17" height="13" rx="2"/><path d="M4 7l8 6 8-6"/></g>,
  download: <g><path d="M12 4v11M7 11l5 5 5-5M5 20h14"/></g>,
  key: <g><circle cx="8" cy="12" r="4.5"/><path d="M12.5 12H21M18 12v3.5M15 12v2.5"/></g>,
  pulse: <g><path d="M3 12h4l2-5 4 10 2-5h6"/></g>,
};

function Icon({ name, size = 16, sw = 1.6, style }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor"
      strokeWidth={sw} strokeLinecap="round" strokeLinejoin="round" style={{ flexShrink: 0, ...style }}>
      {FP_ICONS[name] || <circle cx="12" cy="12" r="8"/>}
    </svg>
  );
}

/* ---------- live data hook ---------- */
function useLive(len, base, vol, interval = 1400) {
  const [data, setData] = useState(() => {
    const arr = []; let v = base;
    for (let i = 0; i < len; i++) { v = Math.max(2, Math.min(98, v + (Math.random() - 0.5) * vol * 2)); arr.push(v); }
    return arr;
  });
  useEffect(() => {
    const id = setInterval(() => {
      setData(d => {
        const last = d[d.length - 1];
        const next = Math.max(2, Math.min(98, last + (Math.random() - 0.5) * vol * 2 + (base - last) * 0.08));
        return [...d.slice(1), next];
      });
    }, interval);
    return () => clearInterval(id);
  }, [base, vol, interval]);
  return data;
}

/* ---------- sparkline / area chart ---------- */
function pathFrom(data, w, h, max = 100) {
  const step = w / (data.length - 1);
  return data.map((v, i) => `${i === 0 ? 'M' : 'L'}${(i * step).toFixed(1)},${(h - (v / max) * h).toFixed(1)}`).join('');
}

function Spark({ data, w = 96, h = 28, color = 'var(--accent)', fill = true, max = 100 }) {
  const p = pathFrom(data, w, h - 2, max);
  const id = useRef('g' + Math.random().toString(36).slice(2, 8)).current;
  return (
    <svg width={w} height={h} style={{ display: 'block', overflow: 'visible' }}>
      {fill && <defs><linearGradient id={id} x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stopColor={color} stopOpacity="0.22"/><stop offset="100%" stopColor={color} stopOpacity="0"/>
      </linearGradient></defs>}
      {fill && <path d={`${p}L${w},${h}L0,${h}Z`} fill={`url(#${id})`} stroke="none"/>}
      <path d={p} fill="none" stroke={color} strokeWidth="1.6" strokeLinejoin="round"/>
      <circle cx={w} cy={h - 2 - (data[data.length - 1] / max) * (h - 2)} r="2.4" fill={color}/>
    </svg>
  );
}

function BigChart({ data, w = 560, h = 140, color = 'var(--accent)', max = 100, unit = '%', grid = 4 }) {
  const p = pathFrom(data, w, h - 8, max);
  const id = useRef('bg' + Math.random().toString(36).slice(2, 8)).current;
  return (
    <svg width="100%" height={h} viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="none" style={{ display: 'block' }}>
      <defs><linearGradient id={id} x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stopColor={color} stopOpacity="0.18"/><stop offset="100%" stopColor={color} stopOpacity="0"/>
      </linearGradient></defs>
      {Array.from({ length: grid }, (_, i) => {
        const y = ((i + 1) / (grid + 1)) * h;
        return <line key={i} x1="0" x2={w} y1={y} y2={y} stroke="var(--line-2)" strokeWidth="1"/>;
      })}
      <path d={`${p}L${w},${h}L0,${h}Z`} fill={`url(#${id})`} stroke="none"/>
      <path d={p} fill="none" stroke={color} strokeWidth="1.8" strokeLinejoin="round" vectorEffect="non-scaling-stroke"/>
    </svg>
  );
}

/* ---------- small atoms ---------- */
function Dot({ tone = 'ok', pulse }) {
  const c = { ok: 'var(--ok)', warn: 'var(--warn)', danger: 'var(--danger)', info: 'var(--info)', idle: 'var(--ink-3)' }[tone];
  return <span style={{ width: 7, height: 7, borderRadius: 99, background: c, display: 'inline-block', flexShrink: 0,
    boxShadow: pulse ? `0 0 0 3px color-mix(in oklab, ${c} 18%, transparent)` : 'none',
    animation: pulse ? 'fp-pulse 2.4s ease-in-out infinite' : 'none' }}></span>;
}

function Badge({ tone = 'ok', children }) {
  const map = { ok: ['var(--ok-soft)', 'var(--accent-deep)'], warn: ['var(--warn-soft)', 'oklch(0.5 0.11 75)'],
    danger: ['var(--danger-soft)', 'oklch(0.47 0.13 25)'], info: ['var(--info-soft)', 'oklch(0.45 0.09 250)'],
    idle: ['var(--surface-3)', 'var(--ink-2)'] };
  const [bg, fg] = map[tone] || map.idle;
  return <span style={{ background: bg, color: fg, fontSize: 'var(--fs-xs)', fontWeight: 600, padding: '2px 7px',
    borderRadius: 5, letterSpacing: '0.01em', whiteSpace: 'nowrap' }}>{children}</span>;
}

function Kbd({ children }) {
  return <kbd style={{ fontFamily: 'var(--font-mono)', fontSize: 10.5, background: 'var(--surface-3)', color: 'var(--ink-2)',
    border: '1px solid var(--line)', borderBottomWidth: 2, borderRadius: 4, padding: '1px 5px', lineHeight: '14px' }}>{children}</kbd>;
}

function Card({ title, right, children, style, pad = true }) {
  return (
    <section style={{ background: 'var(--surface)', border: '1px solid var(--line)', borderRadius: 'var(--radius)',
      boxShadow: 'var(--shadow-1)', display: 'flex', flexDirection: 'column', minWidth: 0, ...style }}>
      {title && <header style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '10px var(--card-pad)',
        borderBottom: '1px solid var(--line-2)' }}>
        <h3 style={{ margin: 0, fontSize: 'var(--fs-sm)', fontWeight: 650, letterSpacing: '0.02em', textTransform: 'uppercase',
          color: 'var(--ink-2)' }}>{title}</h3>
        <div style={{ marginLeft: 'auto', display: 'flex', alignItems: 'center', gap: 8 }}>{right}</div>
      </header>}
      <div style={{ padding: pad ? 'var(--card-pad)' : 0, minHeight: 0, flex: 1 }}>{children}</div>
    </section>
  );
}

function Bar({ pct, tone = 'ok', h = 5 }) {
  const c = { ok: 'var(--ok)', warn: 'var(--warn)', danger: 'var(--danger)', info: 'var(--info)' }[tone];
  return <div style={{ height: h, borderRadius: 99, background: 'var(--surface-3)', overflow: 'hidden', width: '100%' }}>
    <div style={{ height: '100%', width: `${pct}%`, background: c, borderRadius: 99, transition: 'width 0.8s ease' }}></div>
  </div>;
}

function Toggle({ on, onChange, small }) {
  const w = small ? 30 : 36, h = small ? 17 : 20, k = h - 6;
  return (
    <button onClick={() => onChange && onChange(!on)} aria-pressed={on} style={{ width: w, height: h, borderRadius: 99, border: 'none',
      background: on ? 'var(--accent)' : 'var(--line-strong)', position: 'relative', cursor: 'pointer', padding: 0,
      transition: 'background 0.18s', flexShrink: 0 }}>
      <span style={{ position: 'absolute', top: 3, left: on ? w - k - 3 : 3, width: k, height: k, borderRadius: 99,
        background: '#fff', transition: 'left 0.18s', boxShadow: '0 1px 2px rgb(0 0 0 / 0.25)' }}></span>
    </button>
  );
}

function Btn({ children, icon, primary, danger, small, onClick, style }) {
  const [hov, setHov] = useState(false);
  return (
    <button onClick={onClick} onMouseEnter={() => setHov(true)} onMouseLeave={() => setHov(false)}
      style={{ display: 'inline-flex', alignItems: 'center', gap: 6, cursor: 'pointer',
        padding: small ? '4px 9px' : '6px 13px', fontSize: small ? 'var(--fs-xs)' : 'var(--fs-sm)', fontWeight: 600,
        borderRadius: 'var(--radius-sm)',
        border: primary || danger ? '1px solid transparent' : '1px solid var(--line)',
        background: primary ? (hov ? 'var(--accent-deep)' : 'var(--accent)') : danger ? (hov ? 'oklch(0.5 0.14 25)' : 'var(--danger)') : (hov ? 'var(--surface-3)' : 'var(--surface)'),
        color: primary || danger ? '#fff' : 'var(--ink)', transition: 'background 0.15s', whiteSpace: 'nowrap', ...style }}>
      {icon && <Icon name={icon} size={small ? 13 : 14}/>}{children}
    </button>
  );
}

function IconBtn({ name, onClick, title, active, dark }) {
  const [hov, setHov] = useState(false);
  return (
    <button onClick={onClick} title={title} onMouseEnter={() => setHov(true)} onMouseLeave={() => setHov(false)}
      style={{ width: 28, height: 28, display: 'grid', placeItems: 'center', borderRadius: 6, cursor: 'pointer',
        border: 'none', background: active ? (dark ? 'var(--chrome-3)' : 'var(--surface-3)') : hov ? (dark ? 'var(--chrome-2)' : 'var(--surface-3)') : 'transparent',
        color: dark ? (active || hov ? 'var(--chrome-ink)' : 'var(--chrome-ink-2)') : (active ? 'var(--ink)' : 'var(--ink-2)') }}>
      <Icon name={name} size={15}/>
    </button>
  );
}

/* table primitives */
function Th({ children, w, right }) {
  return <th style={{ textAlign: right ? 'right' : 'left', fontSize: 'var(--fs-xs)', fontWeight: 600, color: 'var(--ink-3)',
    textTransform: 'uppercase', letterSpacing: '0.04em', padding: 'calc(var(--row-y) - 2px) 12px', borderBottom: '1px solid var(--line)',
    whiteSpace: 'nowrap', width: w }}>{children}</th>;
}
function Td({ children, right, mono, style }) {
  return <td className={mono ? 'num' : ''} style={{ textAlign: right ? 'right' : 'left', padding: 'var(--row-y) 12px',
    borderBottom: '1px solid var(--line-2)', fontSize: mono ? 'var(--fs-sm)' : 'var(--fs-base)', whiteSpace: 'nowrap', ...style }}>{children}</td>;
}

Object.assign(window, { Icon, useLive, Spark, BigChart, Dot, Badge, Kbd, Card, Bar, Toggle, Btn, IconBtn, Th, Td, pathFrom });
